<?php

namespace App\Policies;

use App\Models\ExpedientType;
use App\Models\User;

class ExpedientTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->isSuperAdministrator();
    }

    public function update(User $user, ExpedientType $expedientType): bool
    {
        return $this->create($user);
    }
}
