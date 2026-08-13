<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_id',
    'storage_disk',
    'storage_path',
    'original_name',
    'mime_type',
    'size_bytes',
    'content_hash',
    'uploaded_by',
])]
/** Metadatos verificables de un binario asociado a un documento. */
class DocumentAttachment extends Model
{
    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
