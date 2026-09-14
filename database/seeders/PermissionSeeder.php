<?php

namespace Database\Seeders;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Actualiza las etiquetas del catálogo sin sobrescribir la matriz elegida por Administración. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCode::cases() as $index => $code) {
            Permission::query()->updateOrCreate(['code' => $code->value], [
                'name' => $code->label(),
                'module' => $code->module(),
                'section' => $code->section(),
                'description' => $code->description(),
                'sort_order' => $index + 1,
            ]);
        }

        foreach (RoleCode::cases() as $roleCode) {
            $role = Role::query()->where('code', $roleCode->value)->firstOrFail();
            if ($role->permissions_configured_at !== null) {
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('code', array_map(fn (PermissionCode $code) => $code->value, PermissionCode::defaultsFor($roleCode)))
                ->pluck('id');

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
