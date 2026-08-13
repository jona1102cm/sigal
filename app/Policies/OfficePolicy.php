<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Office;
use App\Models\User;

/** Separa la consulta operativa del directorio de la modificación del organigrama. */
class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isSuperAdministrator();
    }

    /** The operational directory intentionally exposes no memberships or hierarchy details. */
    public function viewDirectory(User $user): bool
    {
        return $user->isActive() && (
            $user->isSuperAdministrator()
            || $user->hasActiveRole(RoleCode::Observer)
            || $user->hasActiveRole(RoleCode::SimpleUser)
        );
    }

    public function view(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    public function manageMemberships(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }
}
