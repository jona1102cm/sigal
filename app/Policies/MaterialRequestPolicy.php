<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Domain\Warehouse\Enums\MaterialRequestStage;
use App\Domain\Warehouse\Enums\MaterialRequestStatus;
use App\Models\Expedient;
use App\Models\MaterialRequest;
use App\Models\User;

class MaterialRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseView);
    }

    public function view(User $user, MaterialRequest $materialRequest): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return (int) $materialRequest->requesting_user_id === (int) $user->id
            || Expedient::query()->whereKey($materialRequest->expedient_id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseRequest)
            && $user->currentOfficeMemberships()->exists();
    }

    public function update(User $user, MaterialRequest $materialRequest): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseRequest)
            && (int) $materialRequest->requesting_user_id === (int) $user->id
            && $materialRequest->status === MaterialRequestStatus::Draft;
    }

    public function submit(User $user, MaterialRequest $materialRequest): bool
    {
        return $this->update($user, $materialRequest);
    }

    public function revise(User $user, MaterialRequest $materialRequest): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseRequest)
            && (int) $materialRequest->requesting_user_id === (int) $user->id
            && $materialRequest->status === MaterialRequestStatus::Observed
            && $materialRequest->expedient->isCurrentlyHeldByOffice($materialRequest->requesting_office_id);
    }

    public function decide(User $user, MaterialRequest $materialRequest): bool
    {
        if (! $user->hasPermission(PermissionCode::WarehouseApprove)
            || ! in_array($materialRequest->status, [MaterialRequestStatus::Pending, MaterialRequestStatus::InAttention], true)) {
            return false;
        }

        if ($materialRequest->current_stage === MaterialRequestStage::UnitManager) {
            return $user->isCurrentManagerOfOffice($materialRequest->requesting_office_id)
                && (int) $materialRequest->requesting_user_id !== (int) $user->id;
        }

        $officeCode = match ($materialRequest->current_stage) {
            MaterialRequestStage::Omaf => 'OMAF',
            MaterialRequestStage::GoodsServices => 'BIENS',
            default => null,
        };

        return $officeCode !== null && $this->canOperateOfficeStage($user, $materialRequest, $officeCode);
    }

    public function deliver(User $user, MaterialRequest $materialRequest): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseOperate)
            && $materialRequest->status === MaterialRequestStatus::InAttention
            && $materialRequest->current_stage === MaterialRequestStage::Warehouse
            && $this->canOperateOfficeStage($user, $materialRequest, 'AFALM');
    }

    public function authorizeReceiver(User $user, MaterialRequest $materialRequest): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseApprove)
            && $materialRequest->status === MaterialRequestStatus::PendingReceipt
            && $user->isCurrentManagerOfOffice($materialRequest->requesting_office_id);
    }

    public function confirmReceipt(User $user, MaterialRequest $materialRequest): bool
    {
        if (! $user->hasPermission(PermissionCode::WarehouseView)
            || $materialRequest->status !== MaterialRequestStatus::PendingReceipt
            || $materialRequest->delivery === null) {
            return false;
        }

        $canReceive = (int) $materialRequest->requesting_user_id === (int) $user->id
            || (int) $materialRequest->delivery->authorized_receiver_user_id === (int) $user->id;

        return $canReceive && (int) $materialRequest->delivery->delivered_by !== (int) $user->id;
    }

    public function viewAct(User $user, MaterialRequest $materialRequest): bool
    {
        return $this->view($user, $materialRequest)
            && $materialRequest->delivery?->confirmed_at !== null;
    }

    /** @return array<string, bool> */
    public function actions(User $user, MaterialRequest $materialRequest): array
    {
        return [
            'update' => $this->update($user, $materialRequest),
            'submit' => $this->submit($user, $materialRequest),
            'revise' => $this->revise($user, $materialRequest),
            'decide' => $this->decide($user, $materialRequest),
            'deliver' => $this->deliver($user, $materialRequest),
            'authorize_receiver' => $this->authorizeReceiver($user, $materialRequest),
            'confirm_receipt' => $this->confirmReceipt($user, $materialRequest),
            'view_act' => $this->viewAct($user, $materialRequest),
        ];
    }

    private function canOperateOfficeStage(User $user, MaterialRequest $materialRequest, string $officeCode): bool
    {
        $membershipExists = $user->currentOfficeMemberships()
            ->whereHas('office', fn ($office) => $office->where('code', $officeCode))
            ->exists();

        return $membershipExists
            && app(OfficeDocumentAccessService::class)->canOperateExpedient($user, $materialRequest->expedient);
    }
}
