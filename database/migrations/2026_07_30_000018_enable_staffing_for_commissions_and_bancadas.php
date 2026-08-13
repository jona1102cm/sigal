<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            'COM1' => '1. Comisión de Constitución',
            'COM2' => '2. Comisión de RR.II. y Autonomía',
            'COM3' => '3. Comisión de Hacienda',
            'COM4' => '4. Comisión Desarrollo Humano',
            'COM5' => '5. Comisión Derechos Humanos y de Asuntos Indígenas',
            'COM6' => '6. Comisión Desarrollo Económico',
            'COM7' => '7. Comisión de Obras Públicas',
            'BANC1' => '1. Bancada Alianza',
            'BANC2' => '2. Bancada MNR',
            'BANC3' => '3. Bancada Despierta',
            'BANC4' => '4. Bancada TU FE',
            'BANC5' => '5. Bancada NGP',
            'BANC6' => '6. Bancada Campesina',
            'BANC7' => '7. Bancada Indígena',
        ] as $code => $name) {
            $officeId = DB::table('offices')->where('code', $code)->value('id');

            if ($officeId === null) {
                continue;
            }

            DB::table('offices')->where('id', $officeId)->update([
                'supports_staffing' => true,
                'requires_manager' => true,
                'updated_at' => $now,
            ]);

            DB::table('office_positions')->updateOrInsert([
                'office_id' => $officeId,
                'name' => "Responsable de {$name}",
            ], [
                'membership_role' => 'manager',
                'created_by' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('offices')
            ->whereIn('code', [
                'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7',
                'BANC1', 'BANC2', 'BANC3', 'BANC4', 'BANC5', 'BANC6', 'BANC7',
            ])
            ->update([
                'supports_staffing' => false,
                'requires_manager' => false,
                'updated_at' => now(),
            ]);
    }
};
