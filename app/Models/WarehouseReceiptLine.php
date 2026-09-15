<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['warehouse_receipt_id', 'warehouse_item_id', 'quantity', 'unit_cost', 'subtotal', 'lot_number', 'expires_on', 'physical_location'])]
class WarehouseReceiptLine extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'subtotal' => 'decimal:2', 'expires_on' => 'immutable_date'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceipt::class, 'warehouse_receipt_id');
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
