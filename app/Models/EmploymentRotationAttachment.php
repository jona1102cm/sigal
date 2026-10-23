<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employment_rotation_id',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size_bytes',
    'sha256',
    'uploaded_by',
    'uploaded_at',
])]
/** Metadatos y huella del respaldo opcional de una disposición de rotación. */
class EmploymentRotationAttachment extends Model
{
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime'];
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(EmploymentRotation::class, 'employment_rotation_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
