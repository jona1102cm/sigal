<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'expedient_id',
    'document_type_id',
    'legislature_id',
    'issuing_office_id',
    'sequence_number',
    'formatted_number',
    'last_version_number',
])]
/** Serie/número institucional ya reservado y vinculado a un documento. */
class DocumentNumberSeries extends Model
{
    /** @return BelongsTo<Expedient, $this> */
    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return BelongsTo<Legislature, $this> */
    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function issuingOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'issuing_office_id');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
