<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registra las capacidades del nuevo módulo en instalaciones existentes.
     *
     * La migración inicial del catálogo de permisos ya fue ejecutada en producción,
     * por lo que el seeder por sí solo no debe modificar roles personalizados. Esta
     * migración agrega una única vez los permisos mínimos del módulo sin reemplazar
     * ninguna selección que el administrador haya realizado previamente.
     */
    public function up(): void
    {
        $warehousePermissions = [
            PermissionCode::WarehouseView,
            PermissionCode::WarehouseRequest,
            PermissionCode::WarehouseApprove,
            PermissionCode::WarehouseOperate,
            PermissionCode::WarehouseCatalogManage,
        ];
        $now = now();

        foreach ($warehousePermissions as $permission) {
            $attributes = [
                'name' => $permission->label(),
                'module' => $permission->module(),
                'section' => $permission->section(),
                'description' => $permission->description(),
                'sort_order' => array_search($permission, PermissionCode::cases(), true) + 1,
                'updated_at' => $now,
            ];

            if (DB::table('permissions')->where('code', $permission->value)->exists()) {
                DB::table('permissions')->where('code', $permission->value)->update($attributes);
            } else {
                DB::table('permissions')->insert([
                    'code' => $permission->value,
                    ...$attributes,
                    'created_at' => $now,
                ]);
            }
        }

        $this->attachPermissions(RoleCode::SimpleUser, $warehousePermissions, $now);
        $this->attachPermissions(RoleCode::Observer, [PermissionCode::WarehouseView], $now);
    }

    /** @param list<PermissionCode> $permissions */
    private function attachPermissions(RoleCode $roleCode, array $permissions, mixed $now): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode->value)->value('id');
        if ($roleId === null) {
            return;
        }

        foreach ($permissions as $permission) {
            $permissionId = DB::table('permissions')->where('code', $permission->value)->value('id');
            if ($permissionId === null) {
                continue;
            }

            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'assigned_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $codes = [
            PermissionCode::WarehouseView->value,
            PermissionCode::WarehouseRequest->value,
            PermissionCode::WarehouseApprove->value,
            PermissionCode::WarehouseOperate->value,
            PermissionCode::WarehouseCatalogManage->value,
        ];
        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
