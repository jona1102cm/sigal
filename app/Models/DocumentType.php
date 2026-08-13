<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'is_official', 'status'])]
/** Catálogo de clases documentales utilizadas dentro de un expediente. */
class DocumentType extends Model
{
    protected function casts(): array
    {
        return [
            'is_official' => 'boolean',
            'status' => CatalogStatus::class,
        ];
    }

    /** @param Builder<DocumentType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CatalogStatus::Active->value);
    }
}
