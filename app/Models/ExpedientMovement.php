<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\ExpedientPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'expedient_id',
    'sender_office_id',
    'instruction',
    'priority',
    'due_on',
    'requires_response',
    'sent_by',
    'sent_at',
])]
class ExpedientMovement extends Model
{
    protected function casts(): array
    {
        return [
            'priority' => ExpedientPriority::class,
            'due_on' => 'immutable_date',
            'requires_response' => 'boolean',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Expedient, $this> */
    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function senderOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'sender_office_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return HasMany<ExpedientMovementRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(ExpedientMovementRecipient::class);
    }

    /** @return BelongsToMany<Document, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_expedient_movement')
            ->withTimestamps();
    }
}
