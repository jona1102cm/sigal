<?php

namespace Database\Seeders;

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Registra catálogos y estructura institucional idempotentes.
     *
     * Los seeders no crean personas reales: las cuentas administrativas iniciales se
     * generan mediante comandos explícitos para no versionar credenciales.
     */
    public function run(): void
    {
        // firstOrCreate permite ejecutar el seeder más de una vez sin duplicar roles.
        foreach (RoleCode::cases() as $role) {
            Role::query()->firstOrCreate([
                'code' => $role->value,
            ], [
                'name' => $role->label(),
            ]);
        }

        $this->call(PermissionSeeder::class);

        // El orden importa: capacidades y catálogos pueden referenciar oficinas ya sembradas.
        $this->call(OrganizationSeeder::class);
        $this->call(DocumentManagementCatalogSeeder::class);
        $this->call(OfficeCapabilitySeeder::class);
        $this->call(WarehouseCatalogSeeder::class);
    }
}
