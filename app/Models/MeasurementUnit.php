<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'symbol', 'allows_fraction', 'status'])]
/** Unidad normalizada utilizada tanto por el catálogo como por solicitudes libres. */
class MeasurementUnit extends Model
{
    protected function casts(): array
    {
        return ['allows_fraction' => 'boolean', 'status' => CatalogStatus::class];
    }

    public function warehouseItems(): HasMany
    {
        return $this->hasMany(WarehouseItem::class);
    }
}
