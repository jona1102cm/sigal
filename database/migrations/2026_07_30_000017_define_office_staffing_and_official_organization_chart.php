<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->boolean('supports_staffing')->default(true)->after('status');
            $table->boolean('requires_manager')->default(true)->after('supports_staffing');
        });

        $now = now();
        foreach ([
            'VPRES' => 'VPRES1',
            'COMLEG' => 'COMIS',
            'COMP' => 'BIENS',
            'ACTF' => 'AFALM',
            'PRES' => 'PPLAN',
        ] as $oldCode => $newCode) {
            DB::table('offices')->where('code', $oldCode)->update(['code' => $newCode, 'updated_at' => $now]);
        }

        $definitions = [
            ['code' => 'PLENO', 'name' => 'Pleno Asamblea Legislativa', 'parent_code' => null, 'supports_staffing' => false, 'requires_manager' => false],
            ['code' => 'ASES-PLENO', 'name' => 'Asesores del Pleno', 'parent_code' => 'PLENO', 'supports_staffing' => true, 'requires_manager' => false],
            ['code' => 'DIRECTIVA', 'name' => 'Directiva', 'parent_code' => 'PLENO', 'supports_staffing' => false, 'requires_manager' => false],
            ['code' => 'VPRES1', 'name' => '1ra Vicepresidencia de Directiva', 'parent_code' => 'DIRECTIVA', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'SDE1', 'name' => '1ra Secretaría de Directiva', 'parent_code' => 'DIRECTIVA', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'PRESID', 'name' => 'Presidencia de Directiva', 'parent_code' => 'DIRECTIVA', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'ASES-PRES', 'name' => 'Asesores de Presidencia', 'parent_code' => 'PRESID', 'supports_staffing' => true, 'requires_manager' => false],
            ['code' => 'OMAF', 'name' => 'Oficialía Mayor Administrativa y Financiera', 'parent_code' => 'PRESID', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'SGEN', 'name' => 'Secretaría General y Protocolo', 'parent_code' => 'PRESID', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COMIS', 'name' => 'Comisiones', 'parent_code' => 'PRESID', 'supports_staffing' => false, 'requires_manager' => false],
            ['code' => 'BANC', 'name' => 'Bancadas', 'parent_code' => 'PRESID', 'supports_staffing' => false, 'requires_manager' => false],
            ['code' => 'UAF', 'name' => 'Unidad de Administración Financiera', 'parent_code' => 'OMAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'JUR', 'name' => 'Unidad Jurídica', 'parent_code' => 'OMAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'RRHH', 'name' => 'Unidad de Recursos Humanos', 'parent_code' => 'OMAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'SIS', 'name' => 'Sección de Servicios Informáticos', 'parent_code' => 'OMAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COMUN', 'name' => 'Sección de Comunicación', 'parent_code' => 'OMAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'ARCH', 'name' => 'Sección de Registro y Archivo Institucional', 'parent_code' => 'SGEN', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'CONT', 'name' => 'Sección Contabilidad y Cierre', 'parent_code' => 'UAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'PPLAN', 'name' => 'Sección Presupuesto y Planificación', 'parent_code' => 'UAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'TES', 'name' => 'Sección Tesorería', 'parent_code' => 'UAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BIENS', 'name' => 'Sección de Bienes y Servicios', 'parent_code' => 'UAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'PLANILLAS', 'name' => 'Responsable de Elaboración de Planillas', 'parent_code' => 'UAF', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'AFALM', 'name' => 'Activos Fijos y Almacenes', 'parent_code' => 'BIENS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM1', 'name' => '1. Comisión de Constitución', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM2', 'name' => '2. Comisión de RR.II. y Autonomía', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM3', 'name' => '3. Comisión de Hacienda', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM4', 'name' => '4. Comisión Desarrollo Humano', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM5', 'name' => '5. Comisión Derechos Humanos y de Asuntos Indígenas', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM6', 'name' => '6. Comisión Desarrollo Económico', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'COM7', 'name' => '7. Comisión de Obras Públicas', 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC1', 'name' => '1. Bancada Alianza', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC2', 'name' => '2. Bancada MNR', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC3', 'name' => '3. Bancada Despierta', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC4', 'name' => '4. Bancada TU FE', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC5', 'name' => '5. Bancada NGP', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC6', 'name' => '6. Bancada Campesina', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
            ['code' => 'BANC7', 'name' => '7. Bancada Indígena', 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true],
        ];

        foreach ($definitions as $definition) {
            $parentId = $definition['parent_code'] === null
                ? null
                : DB::table('offices')->where('code', $definition['parent_code'])->value('id');
            $officeId = DB::table('offices')->where('code', $definition['code'])->value('id');
            $attributes = [
                'parent_id' => $parentId,
                'name' => $definition['name'],
                'status' => 'active',
                'supports_staffing' => $definition['supports_staffing'],
                'requires_manager' => $definition['requires_manager'],
                'updated_at' => $now,
            ];

            if ($officeId === null) {
                $officeId = DB::table('offices')->insertGetId([
                    ...$attributes,
                    'code' => $definition['code'],
                    'created_at' => $now,
                ]);
            } else {
                DB::table('offices')->where('id', $officeId)->update($attributes);
            }

            if ($definition['requires_manager']) {
                DB::table('office_positions')->updateOrInsert([
                    'office_id' => $officeId,
                    'name' => "Responsable de {$definition['name']}",
                ], [
                    'membership_role' => 'manager',
                    'created_by' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }

        DB::table('offices')->whereIn('code', ['CORR', 'DLEG', 'ALM'])->update([
            'status' => 'inactive',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn(['supports_staffing', 'requires_manager']);
        });
    }
};
