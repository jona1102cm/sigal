<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_id',
    'acting_legislature_member_id',
    'acting_body_membership_id',
    'event',
    'subject_type',
    'subject_id',
    'old_values',
    'new_values',
    'ip_address',
    'user_agent',
    'occurred_at',
])]
/** Evidencia inmutable de una acción con actor, sujeto, contexto y cambios. */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actingLegislatureMember(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'acting_legislature_member_id');
    }

    public function actingBodyMembership(): BelongsTo
    {
        return $this->belongsTo(LegislativeBodyMembership::class, 'acting_body_membership_id');
    }
}
