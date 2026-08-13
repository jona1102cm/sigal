<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name'])]
/** Catálogo estable de roles; la vigencia por usuario vive en UserRoleAssignment. */
class Role extends Model
{
    /**
     * @return HasMany<UserRoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }
}
