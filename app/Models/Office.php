<?php

namespace App\Models;

use App\Domain\Organization\Enums\OfficeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'code', 'name', 'status', 'supports_staffing', 'requires_manager'])]
class Office extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OfficeStatus::class,
            'supports_staffing' => 'boolean',
            'requires_manager' => 'boolean',
        ];
    }

    /** @return BelongsTo<Office, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Office, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<OfficeMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class);
    }

    /** @return HasMany<OfficeMembership, $this> */
    public function currentMemberships(): HasMany
    {
        return $this->memberships()
            ->where('effective_from', '<=', now())
            ->whereNull('effective_to');
    }

    /** @return HasMany<OfficePosition, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(OfficePosition::class);
    }

    /** @return HasMany<OfficeCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(OfficeCapability::class);
    }

    /** @param Builder<Office> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', OfficeStatus::Active->value);
    }

    /** @param Builder<Office> $query */
    public function scopeSupportingStaffing(Builder $query): void
    {
        $query->where('supports_staffing', true);
    }

    public function organizationalProfileLabel(): string
    {
        return match (true) {
            ! $this->supports_staffing => 'Nodo representativo sin personal',
            ! $this->requires_manager => 'Oficina con personal sin responsable',
            default => 'Oficina que requiere responsable',
        };
    }
}
