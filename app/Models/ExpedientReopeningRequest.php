<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\ReopeningRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'expedient_id',
    'requested_by',
    'justification',
    'status',
    'decided_by',
    'decided_at',
    'decision_note',
])]
class ExpedientReopeningRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ReopeningRequestStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Expedient, $this> */
    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
