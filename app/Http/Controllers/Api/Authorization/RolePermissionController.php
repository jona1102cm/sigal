<?php

namespace App\Http\Controllers\Api\Authorization;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Services\RolePermissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Entrega y actualiza la matriz de roles fijos y permisos del sistema. */
class RolePermissionController extends Controller
{
    public function __construct(private readonly RolePermissionService $rolePermissionService) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdministrator(), 403);

        $permissions = Permission::query()->orderBy('sort_order')->get();
        $roles = Role::query()
            ->whereIn('code', array_column(RoleCode::cases(), 'value'))
            ->with('permissions:id,code')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => [
            'permissions' => $permissions->map(fn (Permission $permission) => [
                'code' => $permission->code,
                'name' => $permission->name,
                'module' => $permission->module,
                'section' => $permission->section,
                'description' => $permission->description,
                'super_administrator_only' => PermissionCode::from($permission->code)->isSuperAdministratorOnly(),
            ])->values(),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'protected' => $role->code === RoleCode::SuperAdministrator->value,
                'permission_codes' => $role->code === RoleCode::SuperAdministrator->value
                    ? array_map(fn (PermissionCode $permission) => $permission->value, PermissionCode::cases())
                    : $role->permissions->pluck('code')->sort()->values()->all(),
            ])->values(),
        ]]);
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $role = $this->rolePermissionService->update(
            $role,
            $request->validated('permissions'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'protected' => false,
            'permission_codes' => $role->permissions->pluck('code')->sort()->values()->all(),
        ]]);
    }
}
