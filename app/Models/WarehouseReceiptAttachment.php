<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['warehouse_receipt_id', 'storage_disk', 'storage_path', 'original_name', 'mime_type', 'size_bytes', 'content_hash', 'uploaded_by'])]
class WarehouseReceiptAttachment extends Model
{
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(WarehouseReceipt::class, 'warehouse_receipt_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
