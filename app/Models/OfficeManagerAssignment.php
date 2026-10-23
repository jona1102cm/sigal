<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'office_membership_id',
    'user_id',
    'effective_from',
    'effective_to',
    'source',
    'designation_note',
    'closure_note',
    'designated_by',
    'ended_by',
])]
/** Proyección histórica del cargo contractual responsable de una oficina. */
class OfficeManagerAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function officeMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function designatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designated_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function delegations(): HasMany
    {
        return $this->hasMany(OfficeManagerDelegation::class, 'principal_assignment_id');
    }
}
