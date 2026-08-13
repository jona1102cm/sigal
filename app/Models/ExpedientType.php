<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'category', 'status'])]
/** Catálogo administrable que clasifica el tipo de proceso administrativo. */
class ExpedientType extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
        ];
    }

    /** @param Builder<ExpedientType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CatalogStatus::Active->value);
    }
}
