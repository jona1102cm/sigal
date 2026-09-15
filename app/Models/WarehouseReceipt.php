<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['receipt_number', 'supplier_name', 'supplier_tax_id', 'reference_type', 'reference_number', 'reference_date', 'received_on', 'currency', 'total_amount', 'observations', 'registered_by', 'warehouse_responsible_user_id', 'posted_at'])]
class WarehouseReceipt extends Model
{
    protected function casts(): array
    {
        return ['reference_date' => 'immutable_date', 'received_on' => 'immutable_date', 'total_amount' => 'decimal:2', 'posted_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseReceiptLine::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WarehouseReceiptAttachment::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function warehouseResponsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'warehouse_responsible_user_id');
    }
}
