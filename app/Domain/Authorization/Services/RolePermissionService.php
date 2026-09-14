<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Actualiza en una transacción la matriz global de permisos de un rol fijo. */
class RolePermissionService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @param list<string> $permissionCodes */
    public function update(Role $role, array $permissionCodes, User $actor, RequestAuditContext $context): Role
    {
        return DB::transaction(function () use ($role, $permissionCodes, $actor, $context): Role {
            $target = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->id);
            $roleCode = RoleCode::tryFrom($target->code);

            if ($roleCode === null) {
                throw ValidationException::withMessages(['role' => 'El rol no pertenece al catálogo institucional.']);
            }

            if ($roleCode === RoleCode::SuperAdministrator) {
                throw ValidationException::withMessages([
                    'role' => 'Los permisos del Superadministrador son obligatorios y no pueden modificarse.',
                ]);
            }

            $permissions = Permission::query()->whereIn('code', array_values(array_unique($permissionCodes)))->get();
            $protected = $permissions
                ->filter(fn (Permission $permission) => PermissionCode::from($permission->code)->isSuperAdministratorOnly());

            if ($protected->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'permissions' => 'Las funciones de Administración son exclusivas del Superadministrador.',
                ]);
            }

            $oldValues = ['permissions' => $target->permissions->pluck('code')->sort()->values()->all()];
            $sync = $permissions->mapWithKeys(fn (Permission $permission) => [
                $permission->id => ['assigned_by' => $actor->id],
            ])->all();
            $target->permissions()->sync($sync);
            $target->update(['permissions_configured_at' => now()]);
            $target->load('permissions');

            $this->activityLogger->record(
                event: 'authorization.role_permissions.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: ['permissions' => $target->permissions->pluck('code')->sort()->values()->all()],
            );

            return $target;
        });
    }
}
