<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'requires_explicit_access', 'sort_order', 'status'])]
/** Catálogo de niveles que condicionan la visibilidad documental. */
class ConfidentialityLevel extends Model
{
    protected function casts(): array
    {
        return [
            'requires_explicit_access' => 'boolean',
            'status' => CatalogStatus::class,
        ];
    }

    /** @param Builder<ConfidentialityLevel> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CatalogStatus::Active->value);
    }
}
