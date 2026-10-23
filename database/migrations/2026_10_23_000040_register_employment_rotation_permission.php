<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permission = PermissionCode::HumanResourcesRotations;
        $now = now();
        $attributes = [
            'name' => $permission->label(),
            'module' => $permission->module(),
            'section' => $permission->section(),
            'description' => $permission->description(),
            'sort_order' => array_search($permission, PermissionCode::cases(), true) + 1,
            'updated_at' => $now,
        ];

        DB::table('permissions')->updateOrInsert(
            ['code' => $permission->value],
            [...$attributes, 'created_at' => $now],
        );

        $permissionId = DB::table('permissions')->where('code', $permission->value)->value('id');
        $roleIds = DB::table('roles')
            ->whereIn('code', [RoleCode::SuperAdministrator->value, RoleCode::HumanResourcesManager->value])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
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
        $permissionId = DB::table('permissions')
            ->where('code', PermissionCode::HumanResourcesRotations->value)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
