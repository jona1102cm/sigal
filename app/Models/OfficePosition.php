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
}
