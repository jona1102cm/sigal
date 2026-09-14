<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'permissions_configured_at'])]
/** Catálogo estable de roles; la vigencia por usuario vive en UserRoleAssignment. */
class Role extends Model
{
    protected function casts(): array
    {
        return ['permissions_configured_at' => 'immutable_datetime'];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * @return HasMany<UserRoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }
}
