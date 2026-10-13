<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['legislative_body_id', 'principal_membership_id', 'delegate_membership_id', 'effective_from', 'effective_to', 'reason', 'created_by'])]
/** Sustitución temporal y auditable de la autoridad de una bancada o comisión. */
class LegislativeDelegation extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function body(): BelongsTo
    {
        return $this->belongsTo(LegislativeBody::class, 'legislative_body_id');
    }

    public function principalMembership(): BelongsTo
    {
        return $this->belongsTo(LegislativeBodyMembership::class, 'principal_membership_id');
    }

    public function delegateMembership(): BelongsTo
    {
        return $this->belongsTo(LegislativeBodyMembership::class, 'delegate_membership_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
