<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'code', 'name', 'description', 'status', 'created_by'])]
class WarehouseCategory extends Model
{
    protected function casts(): array
    {
        return ['status' => CatalogStatus::class];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseItem::class);
    }
}
