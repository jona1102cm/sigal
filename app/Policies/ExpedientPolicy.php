<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Expedient;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Fuente autoritativa de visibilidad y acciones sobre expedientes.
 * Evalúa rol, creación, membresía, jerarquía, tenencia, acceso concedido y capacidades.
 */
class ExpedientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::ExpedientsView);
    }

    public function view(User $user, Expedient $expedient): bool
    {
        return $this->viewAny($user)
            && Expedient::query()->whereKey($expedient->id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionCode::ExpedientsCreate);
    }

    public function move(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['move'];
    }

    public function actOnMovement(User $user, Expedient $expedient): bool
    {
        return $this->move($user, $expedient);
    }

    public function manageAccess(User $user, Expedient $expedient): bool
    {
        return $user->isActive() && $user->isSuperAdministrator();
    }

    public function manageDocuments(User $user, Expedient $expedient): bool
    {
        return $this->move($user, $expedient);
    }

    public function manageInternalAssignments(User $user, Expedient $expedient): bool
    {
        return $this->view($user, $expedient)
            && $user->hasPermission(PermissionCode::OfficeAccessConfigure)
            && app(OfficeDocumentAccessService::class)->canManageInternalAssignments($user, $expedient);
    }

    public function viewLifecycle(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['view_lifecycle'];
    }

    public function viewReopeningHistory(User $user, Expedient $expedient): bool
    {
        $permissions = $this->detailPermissions($user, $expedient);

        return $permissions['view_lifecycle']
            || $permissions['request_reopening']
            || $permissions['approve_reopening'];
    }

    public function manageLifecycle(User $user, Expedient $expedient): bool
    {
        return $this->archive($user, $expedient)
            || $this->close($user, $expedient)
            || $this->void($user, $expedient);
    }

    public function archive(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['archive'];
    }

    public function close(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['close'];
    }

    public function void(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['void'];
    }

    public function requestReopening(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['request_reopening'];
    }

    public function approveReopening(User $user, Expedient $expedient): bool
    {
        return $this->detailPermissions($user, $expedient)['approve_reopening'];
    }

    /**
     * Resuelve de una sola vez las capacidades del detalle para evitar consultas
     * repetidas y garantizar que API, Policies e interfaz compartan la misma matriz.
     *
     * @return array<string, mixed>
     */
    public function detailPermissions(User $user, Expedient $expedient): array
    {
        $canView = $this->view($user, $expedient);
        $permissions = [
            'move' => false,
            'act_on_movement' => false,
            'manage_documents' => false,
            'manage_access' => $user->isActive() && $user->isSuperAdministrator(),
            'view_lifecycle' => false,
            'archive' => false,
            'close' => false,
            'void' => false,
            'request_reopening' => false,
            'approve_reopening' => false,
            'manage_internal_assignments' => false,
            'internal_assignment_office_ids' => [],
        ];

        if (! $canView) {
            return $permissions;
        }

        $holderOfficeIds = $expedient->currentHolderOfficeIds();
        $memberships = $user->currentOfficeMemberships()->with('office.capabilities')->get();
        $holderMemberships = $memberships->whereIn('office_id', $holderOfficeIds);
        $canOperate = $user->hasPermission(PermissionCode::ExpedientsProcess)
            && app(OfficeDocumentAccessService::class)->canOperateExpedient($user, $expedient);
        $canMove = $canOperate
            && ! in_array($expedient->status, [
                ExpedientStatus::Archived,
                ExpedientStatus::Closed,
                ExpedientStatus::Voided,
            ], true);

        return [
            ...$permissions,
            'move' => $canMove,
            'act_on_movement' => $canMove,
            'manage_documents' => $canMove,
            // Archivar no elimina la custodia: Archivo Central todavía debe poder
            // cerrar el trámite y consultar el historial de ciclo de vida.
            'view_lifecycle' => $canOperate,
            'manage_internal_assignments' => $this->manageInternalAssignments($user, $expedient),
            'internal_assignment_office_ids' => app(OfficeDocumentAccessService::class)
                ->manageableInternalAssignmentOfficeIds($user, $expedient)->all(),
            'archive' => $canMove && $this->membershipsHaveCapability($holderMemberships, OfficeCapabilityCode::ArchiveExpedients),
            'close' => $canOperate
                && ! in_array($expedient->status, [ExpedientStatus::Closed, ExpedientStatus::Voided], true)
                && $this->membershipsHaveCapability($holderMemberships, OfficeCapabilityCode::CloseExpedients),
            'void' => $canOperate
                && $expedient->status !== ExpedientStatus::Voided
                && $this->membershipsHaveCapability($holderMemberships, OfficeCapabilityCode::VoidExpedients),
            'request_reopening' => $memberships->contains(fn ($membership) => (int) $membership->office_id === (int) $expedient->responsible_office_id
                && $membership->membership_role === OfficeMembershipRole::Manager),
            'approve_reopening' => $memberships->contains(fn ($membership) => $membership->membership_role === OfficeMembershipRole::Manager
                && $this->membershipHasCapability($membership, OfficeCapabilityCode::ApproveReopenings)),
        ];
    }

    /** @param Collection<int, mixed> $memberships */
    private function membershipsHaveCapability(Collection $memberships, OfficeCapabilityCode $capability): bool
    {
        return $memberships->contains(fn ($membership) => $this->membershipHasCapability($membership, $capability));
    }

    private function membershipHasCapability(mixed $membership, OfficeCapabilityCode $capability): bool
    {
        return $membership->office?->capabilities->contains(
            fn ($officeCapability) => $officeCapability->capability === $capability,
        ) ?? false;
    }
}
