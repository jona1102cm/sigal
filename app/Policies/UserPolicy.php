<?php

namespace App\Policies;

use App\Models\User;

/** Autoriza la administración de identidades y protege la continuidad administrativa. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isSuperAdministrator();
    }

    public function view(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function manageRoles(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function resetPassword(User $user, User $subject): bool
    {
        return $this->viewAny($user) && $user->isNot($subject);
    }
}
