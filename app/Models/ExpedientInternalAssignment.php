<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\InternalAssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['expedient_movement_recipient_id', 'user_id', 'assignment_role', 'assigned_by', 'effective_from', 'effective_to'])]
/** Responsable o colaborador asignado dentro de una oficina, sin crear una derivación externa. */
class ExpedientInternalAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'assignment_role' => InternalAssignmentRole::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(ExpedientMovementRecipient::class, 'expedient_movement_recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
