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
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (RoleCode::cases() as $role) {
            Role::query()->firstOrCreate([
                'code' => $role->value,
            ], [
                'name' => $role->label(),
            ]);
        }

        $this->call(OrganizationSeeder::class);
        $this->call(DocumentManagementCatalogSeeder::class);
        $this->call(OfficeCapabilitySeeder::class);
    }
}
