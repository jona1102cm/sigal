<?php

namespace App\Domain\Authorization\Concerns;

use App\Domain\Authorization\Enums\PermissionCode;
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

    public function hasPermission(PermissionCode|string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        // La cuenta de emergencia institucional nunca depende de una fila de pivote faltante.
        if ($this->isSuperAdministrator()) {
            return true;
        }

        $code = $permission instanceof PermissionCode ? $permission->value : $permission;
        $now = now();

        return $this->roleAssignments()
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now))
            ->whereHas('role.permissions', fn ($query) => $query->where('code', $code))
            ->exists();
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        if (! $this->isActive()) {
            return [];
        }

        if ($this->isSuperAdministrator()) {
            return array_map(fn (PermissionCode $permission) => $permission->value, PermissionCode::cases());
        }

        $now = now();

        return $this->roleAssignments()
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now))
            ->with('role.permissions:id,code')
            ->get()
            ->flatMap(fn (UserRoleAssignment $assignment) => $assignment->role->permissions->pluck('code'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
