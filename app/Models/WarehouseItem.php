<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['warehouse_category_id', 'measurement_unit_id', 'code', 'name', 'description', 'minimum_stock', 'stock_on_hand', 'physical_location', 'status', 'created_by'])]
/** Material consumible inventariable; los bienes patrimoniales tendrán un agregado distinto. */
class WarehouseItem extends Model
{
    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:4',
            'stock_on_hand' => 'decimal:4',
            'status' => CatalogStatus::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WarehouseCategory::class, 'warehouse_category_id');
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(WarehouseStockMovement::class);
    }
}
