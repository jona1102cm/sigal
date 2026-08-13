<?php

namespace App\Domain\Authorization\Concerns;

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

trait HasSystemRoles
{
    /**
     * @return HasMany<UserRoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function hasActiveRole(RoleCode $role, ?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->roleAssignments()
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $at))
            ->whereHas('role', fn ($query) => $query->where('code', $role->value))
            ->exists();
    }

    public function isSuperAdministrator(): bool
    {
        return $this->hasActiveRole(RoleCode::SuperAdministrator);
    }

    public function isHumanResourcesManager(): bool
    {
        return $this->hasActiveRole(RoleCode::HumanResourcesManager);
    }
}
