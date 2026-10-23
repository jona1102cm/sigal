<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'principal_assignment_id',
    'delegate_membership_id',
    'delegate_user_id',
    'effective_from',
    'effective_to',
    'reason',
    'created_by',
    'cancelled_at',
    'ended_by',
])]
/** Sustitución temporal del responsable contractual de una oficina. */
class OfficeManagerDelegation extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function principalAssignment(): BelongsTo
    {
        return $this->belongsTo(OfficeManagerAssignment::class, 'principal_assignment_id');
    }

    public function delegateMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'delegate_membership_id');
    }

    public function delegateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
