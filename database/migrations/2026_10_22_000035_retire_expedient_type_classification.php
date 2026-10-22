<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los expedientes nuevos dejan de recibir una clasificación fija. La columna
     * permanece nullable para conservar intactos los valores históricos.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE expedients ALTER COLUMN expedient_type_id DROP NOT NULL');

        $permissionId = DB::table('permissions')
            ->where('code', 'administration.expedient_types.manage')
            ->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    /**
     * La reversión crea una marca exclusivamente técnica para satisfacer el
     * esquema anterior sin adjudicar una clasificación falsa a los históricos.
     */
    public function down(): void
    {
        $now = now();

        DB::table('expedient_types')->insertOrIgnore([
            'code' => 'LEGACY_UNCLASSIFIED',
            'name' => 'Sin clasificación heredada',
            'category' => 'Técnico',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $legacyTypeId = DB::table('expedient_types')
            ->where('code', 'LEGACY_UNCLASSIFIED')
            ->value('id');

        DB::table('expedients')
            ->whereNull('expedient_type_id')
            ->update(['expedient_type_id' => $legacyTypeId]);

        DB::statement('ALTER TABLE expedients ALTER COLUMN expedient_type_id SET NOT NULL');

        DB::table('permissions')->insertOrIgnore([
            'code' => 'administration.expedient_types.manage',
            'name' => 'Administrar tipos de expediente',
            'module' => 'Administración',
            'section' => 'Tipos de expediente',
            'description' => 'Habilita esta vista o acción; el backend vuelve a validar el alcance de los datos.',
            'sort_order' => ((int) DB::table('permissions')->max('sort_order')) + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('code', 'administration.expedient_types.manage')
            ->value('id');
        $roleId = DB::table('roles')->where('code', 'super_administrator')->value('id');

        if ($permissionId !== null && $roleId !== null) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
