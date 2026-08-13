<?php

namespace App\Models;

use App\Domain\HumanResources\Enums\ContractType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'employee_id',
    'office_position_id',
    'contract_type',
    'contract_amount',
    'starts_on',
    'ends_on',
    'ended_at',
    'ended_by',
    'created_by',
])]
/** Relación laboral histórica que origina cargo, membresía y rol durante su vigencia. */
class EmploymentContract extends Model
{
    protected function casts(): array
    {
        return [
            'contract_type' => ContractType::class,
            'contract_amount' => 'decimal:2',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<OfficePosition, $this> */
    public function officePosition(): BelongsTo
    {
        return $this->belongsTo(OfficePosition::class);
    }

    /** @return BelongsTo<User, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<OfficeMembership, $this> */
    public function officeMembership(): HasOne
    {
        return $this->hasOne(OfficeMembership::class);
    }

    /** @return HasMany<UserRoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
