<?php

namespace App\Models;

use App\Domain\Warehouse\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['warehouse_item_id', 'movement_type', 'quantity_delta', 'balance_after', 'unit_cost', 'warehouse_receipt_line_id', 'warehouse_delivery_line_id', 'reason', 'performed_by', 'occurred_at'])]
class WarehouseStockMovement extends Model
{
    protected function casts(): array
    {
        return ['movement_type' => StockMovementType::class, 'quantity_delta' => 'decimal:4', 'balance_after' => 'decimal:4', 'unit_cost' => 'decimal:4', 'occurred_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceiptLine::class, 'warehouse_receipt_line_id');
    }

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(WarehouseDeliveryLine::class, 'warehouse_delivery_line_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
