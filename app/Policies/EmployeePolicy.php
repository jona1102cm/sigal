<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->manage($user);
    }

    public function manageContracts(User $user, Employee $employee): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $user->isActive() && ($user->isSuperAdministrator() || $user->isHumanResourcesManager());
    }
}
