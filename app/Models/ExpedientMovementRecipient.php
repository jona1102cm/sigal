<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'expedient_movement_id',
    'recipient_office_id',
    'recipient_kind',
    'status',
    'action_note',
    'received_by',
    'received_at',
    'completed_by',
    'completed_at',
])]
/** Estado independiente de una oficina destinataria primaria o en copia. */
class ExpedientMovementRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'recipient_kind' => MovementRecipientKind::class,
            'status' => MovementRecipientStatus::class,
            'received_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ExpedientMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(ExpedientMovement::class, 'expedient_movement_id');
    }

    /** @return BelongsTo<Office, $this> */
    public function recipientOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'recipient_office_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function internalAssignments(): HasMany
    {
        return $this->hasMany(ExpedientInternalAssignment::class);
    }

    public function currentInternalAssignments(): HasMany
    {
        return $this->internalAssignments()
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }
}
