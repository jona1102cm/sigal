<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employment_contract_id', 'user_id', 'role_id', 'assigned_by', 'effective_from', 'effective_to'])]
/** Intervalo histórico durante el cual un usuario posee un rol del sistema. */
class UserRoleAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<EmploymentContract, $this> */
    public function employmentContract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return HasMany<ObserverOfficeScope, $this> */
    public function observerOfficeScopes(): HasMany
    {
        return $this->hasMany(ObserverOfficeScope::class);
    }

    /** @return HasMany<ObserverOfficeScope, $this> */
    public function currentObserverOfficeScopes(): HasMany
    {
        return $this->observerOfficeScopes()
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }
}
