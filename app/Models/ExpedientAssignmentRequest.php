<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\AssignmentRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'expedient_movement_recipient_id',
    'requested_by',
    'justification',
    'status',
    'decided_by',
    'decision_note',
    'requested_at',
    'decided_at',
])]
/** Solicitud auditable para transferir la responsabilidad operativa interna. */
class ExpedientAssignmentRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AssignmentRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(ExpedientMovementRecipient::class, 'expedient_movement_recipient_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
