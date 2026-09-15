<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['material_request_id', 'revision_number', 'sort_order', 'warehouse_item_id', 'measurement_unit_id', 'item_name', 'requested_quantity', 'notes'])]
class MaterialRequestItem extends Model
{
    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:4'];
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function warehouseItem(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }
}
