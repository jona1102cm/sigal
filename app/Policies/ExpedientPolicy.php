<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Expedient;
use App\Models\User;

class ExpedientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && (
            $user->isSuperAdministrator()
            || $user->hasActiveRole(RoleCode::Observer)
            || $user->hasActiveRole(RoleCode::SimpleUser)
        );
    }

    public function view(User $user, Expedient $expedient): bool
    {
        return $this->viewAny($user)
            && Expedient::query()->whereKey($expedient->id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && (
            $user->isSuperAdministrator() || $user->hasActiveRole(RoleCode::SimpleUser)
        );
    }

    public function move(User $user, Expedient $expedient): bool
    {
        return $this->view($user, $expedient)
            && $this->isHeldByUser($user, $expedient)
            && ! in_array($expedient->status, [
                ExpedientStatus::Archived,
                ExpedientStatus::Closed,
                ExpedientStatus::Voided,
            ], true);
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

    public function manageLifecycle(User $user, Expedient $expedient): bool
    {
        if (! $this->view($user, $expedient) || ! $this->isHeldByUser($user, $expedient)) {
            return false;
        }

        $holderOfficeIds = $expedient->currentHolderOfficeIds();

        return $user->currentOfficeMemberships()
            ->whereIn('office_id', $holderOfficeIds)
            ->where(function ($membership): void {
                $membership->where('membership_role', OfficeMembershipRole::Manager->value)
                    ->orWhereHas('office.capabilities', fn ($capability) => $capability->whereIn('capability', [
                        OfficeCapabilityCode::ArchiveExpedients->value,
                        OfficeCapabilityCode::CloseExpedients->value,
                        OfficeCapabilityCode::VoidExpedients->value,
                    ]));
            })
            ->exists();
    }

    public function requestReopening(User $user, Expedient $expedient): bool
    {
        return $this->view($user, $expedient)
            && $user->isCurrentManagerOfOffice($expedient->responsible_office_id);
    }

    public function approveReopening(User $user, Expedient $expedient): bool
    {
        return $this->view($user, $expedient)
            && $user->hasCurrentOfficeCapability(OfficeCapabilityCode::ApproveReopenings, requiresManager: true);
    }

    private function isHeldByUser(User $user, Expedient $expedient): bool
    {
        return $user->currentOfficeMemberships()
            ->whereIn('office_id', $expedient->currentHolderOfficeIds())
            ->exists();
    }
}
