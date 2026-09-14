<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\Employee;
use App\Models\User;

/** Limita kardex y contratos a superadministración y administración de RR. HH. */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::HumanResourcesView);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasPermission(PermissionCode::HumanResourcesView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionCode::HumanResourcesManage);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission(PermissionCode::HumanResourcesManage);
    }

    public function manageContracts(User $user, Employee $employee): bool
    {
        return $user->hasPermission(PermissionCode::HumanResourcesContracts);
    }
}
