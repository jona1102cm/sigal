<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\ExpedientType;
use App\Models\User;

/** Autoriza la lectura operativa y la administración separada del catálogo. */
class ExpedientTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::ExpedientsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionCode::ExpedientTypesManage);
    }

    public function update(User $user, ExpedientType $expedientType): bool
    {
        return $this->create($user);
    }
}
