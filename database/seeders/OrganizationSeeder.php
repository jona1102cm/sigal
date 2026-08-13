<?php

namespace Database\Seeders;

use App\Domain\Organization\Enums\OfficeStatus;
use App\Models\Office;
use Illuminate\Database\Seeder;

/** Registra de forma idempotente el organigrama oficial y sus cargos iniciales. */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
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
        ];

        foreach ([
            '1. Comisión de Constitución',
            '2. Comisión de RR.II. y Autonomía',
            '3. Comisión de Hacienda',
            '4. Comisión Desarrollo Humano',
            '5. Comisión Derechos Humanos y de Asuntos Indígenas',
            '6. Comisión Desarrollo Económico',
            '7. Comisión de Obras Públicas',
        ] as $index => $name) {
            $offices[] = ['code' => 'COM'.($index + 1), 'name' => $name, 'parent_code' => 'COMIS', 'supports_staffing' => true, 'requires_manager' => true];
        }

        foreach (['Alianza', 'MNR', 'Despierta', 'TU FE', 'NGP', 'Campesina', 'Indígena'] as $index => $name) {
            $offices[] = ['code' => 'BANC'.($index + 1), 'name' => ($index + 1).". Bancada {$name}", 'parent_code' => 'BANC', 'supports_staffing' => true, 'requires_manager' => true];
        }

        foreach ($offices as $definition) {
            $parentId = $definition['parent_code'] === null
                ? null
                : Office::query()->where('code', $definition['parent_code'])->value('id');

            Office::query()->updateOrCreate([
                'code' => $definition['code'],
            ], [
                'parent_id' => $parentId,
                'name' => $definition['name'],
                'status' => OfficeStatus::Active,
                'supports_staffing' => $definition['supports_staffing'],
                'requires_manager' => $definition['requires_manager'],
            ]);
        }
    }
}
