<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'employment_contract_id',
    'source_office_membership_id',
    'destination_office_position_id',
    'effective_from',
    'effective_to',
    'reason',
    'memorandum_number',
    'memorandum_date',
    'created_by',
    'ended_by',
])]
/** Disposición histórica que cambia el destino operativo sin alterar el contrato presupuestario. */
class EmploymentRotation extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'memorandum_date' => 'immutable_date',
        ];
    }

    public function employmentContract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class);
    }

    public function sourceOfficeMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'source_office_membership_id');
    }

    public function destinationOfficePosition(): BelongsTo
    {
        return $this->belongsTo(OfficePosition::class, 'destination_office_position_id');
    }

    public function destinationOfficeMembership(): HasOne
    {
        return $this->hasOne(OfficeMembership::class);
    }

    public function attachment(): HasOne
    {
        return $this->hasOne(EmploymentRotationAttachment::class);
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
