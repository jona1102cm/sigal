<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'module', 'section', 'description', 'sort_order'])]
/** Capacidad estable que las Policies verifican y la interfaz agrupa por módulo. */
class Permission extends Model
{
    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('assigned_by')
            ->withTimestamps();
    }
}
