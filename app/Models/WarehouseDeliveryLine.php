<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['warehouse_delivery_id', 'material_request_item_id', 'warehouse_item_id', 'requested_quantity_snapshot', 'delivered_quantity', 'over_delivery_reason'])]
class WarehouseDeliveryLine extends Model
{
    protected function casts(): array
    {
        return ['requested_quantity_snapshot' => 'decimal:4', 'delivered_quantity' => 'decimal:4'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WarehouseDelivery::class, 'warehouse_delivery_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestItem::class, 'material_request_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function stockMovement(): HasOne
    {
        return $this->hasOne(WarehouseStockMovement::class);
    }
}
