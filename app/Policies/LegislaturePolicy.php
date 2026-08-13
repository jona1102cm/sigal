<?php

namespace App\Policies;

use App\Models\Legislature;
use App\Models\User;

class LegislaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdministrator();
    }

    public function view(User $user, Legislature $legislature): bool
    {
        return $user->isSuperAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdministrator();
    }

    public function update(User $user, Legislature $legislature): bool
    {
        return $user->isSuperAdministrator();
    }
}
