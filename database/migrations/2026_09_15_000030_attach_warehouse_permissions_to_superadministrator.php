<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Mantiene la matriz visual del superadministrador alineada con su acceso global efectivo. */
    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', RoleCode::SuperAdministrator->value)->value('id');
        if ($roleId === null) {
            return;
        }

        $now = now();
        foreach ($this->permissionCodes() as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
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
        $roleId = DB::table('roles')->where('code', RoleCode::SuperAdministrator->value)->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('code', $this->permissionCodes())->pluck('id');
        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }

    /** @return list<string> */
    private function permissionCodes(): array
    {
        return [
            PermissionCode::WarehouseView->value,
            PermissionCode::WarehouseRequest->value,
            PermissionCode::WarehouseApprove->value,
            PermissionCode::WarehouseOperate->value,
            PermissionCode::WarehouseCatalogManage->value,
        ];
    }
};
