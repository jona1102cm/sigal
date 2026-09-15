<?php

namespace Database\Seeders;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\MeasurementUnit;
use App\Models\WarehouseCategory;
use Illuminate\Database\Seeder;

/** Catálogos mínimos reutilizables; los materiales concretos los registra Almacenes. */
class WarehouseCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'UNIT', 'name' => 'Unidad', 'symbol' => 'unid.', 'allows_fraction' => false],
            ['code' => 'PACKAGE', 'name' => 'Paquete', 'symbol' => 'paq.', 'allows_fraction' => false],
            ['code' => 'BOX', 'name' => 'Caja', 'symbol' => 'caja', 'allows_fraction' => false],
            ['code' => 'REAM', 'name' => 'Resma', 'symbol' => 'resma', 'allows_fraction' => false],
            ['code' => 'LITER', 'name' => 'Litro', 'symbol' => 'L', 'allows_fraction' => true],
            ['code' => 'KILOGRAM', 'name' => 'Kilogramo', 'symbol' => 'kg', 'allows_fraction' => true],
            ['code' => 'METER', 'name' => 'Metro', 'symbol' => 'm', 'allows_fraction' => true],
            ['code' => 'ROLL', 'name' => 'Rollo', 'symbol' => 'rollo', 'allows_fraction' => false],
            ['code' => 'BOTTLE', 'name' => 'Botella', 'symbol' => 'bot.', 'allows_fraction' => false],
        ] as $unit) {
            MeasurementUnit::query()->updateOrCreate(['code' => $unit['code']], $unit + ['status' => CatalogStatus::Active]);
        }

        foreach ([
            ['code' => 'OFFICE_SUPPLIES', 'name' => 'Material de escritorio'],
            ['code' => 'PAPER', 'name' => 'Papelería'],
            ['code' => 'CLEANING', 'name' => 'Limpieza e higiene'],
            ['code' => 'FOOD_BEVERAGES', 'name' => 'Alimentos y bebidas'],
            ['code' => 'COMPUTER_SUPPLIES', 'name' => 'Insumos informáticos'],
            ['code' => 'ELECTRICAL', 'name' => 'Material eléctrico y pilas'],
            ['code' => 'OTHER_CONSUMABLES', 'name' => 'Otros materiales consumibles'],
        ] as $category) {
            WarehouseCategory::query()->updateOrCreate(['code' => $category['code']], $category + ['status' => CatalogStatus::Active]);
        }
    }
}
