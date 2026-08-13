<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'expedient_id',
    'document_type_id',
    'issuing_office_id',
    'office_reference',
    'is_initial',
    'origin_number',
    'origin_date',
    'document_number_series_id',
    'supersedes_document_id',
    'version_number',
    'status',
    'title',
    'content',
    'created_by',
    'issued_by',
    'issued_at',
])]
/** Pieza documental perteneciente obligatoriamente a un expediente y una oficina emisora. */
class Document extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'is_initial' => 'boolean',
            'origin_date' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
        ];
    }

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

    /** @return BelongsTo<Office, $this> */
    public function issuingOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'issuing_office_id');
    }

    /** @return BelongsTo<DocumentNumberSeries, $this> */
    public function numberSeries(): BelongsTo
    {
        return $this->belongsTo(DocumentNumberSeries::class, 'document_number_series_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_document_id');
    }

    /** @return HasMany<Document, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return HasMany<DocumentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class);
    }

    /** @return HasMany<DocumentAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    /** @return BelongsToMany<ExpedientMovement, $this> */
    public function movements(): BelongsToMany
    {
        return $this->belongsToMany(ExpedientMovement::class, 'document_expedient_movement')
            ->withTimestamps();
    }
}
