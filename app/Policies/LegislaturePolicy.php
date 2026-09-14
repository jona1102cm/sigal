<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\Legislature;
use App\Models\User;

/** Restringe períodos y Directiva a usuarios con administración global. */
class LegislaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::LegislaturesManage);
    }

    public function view(User $user, Legislature $legislature): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Legislature $legislature): bool
    {
        return $this->viewAny($user);
    }
}
