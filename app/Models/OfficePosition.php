<?php

namespace App\Models;

use App\Domain\Organization\Enums\OfficeMembershipRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['office_id', 'name', 'membership_role', 'created_by'])]
/** Cargo del catálogo propio de una oficina, con función de responsable u oficial. */
class OfficePosition extends Model
{
    protected function casts(): array
    {
        return ['membership_role' => OfficeMembershipRole::class];
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EmploymentContract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    public function officeMemberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class);
    }

    /** Funcionarios que ocupan actualmente este cargo dentro de la oficina. */
    public function currentMemberships(): HasMany
    {
        $now = now();

        return $this->officeMemberships()
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now));
    }

    public function destinationRotations(): HasMany
    {
        return $this->hasMany(EmploymentRotation::class, 'destination_office_position_id');
    }
}
