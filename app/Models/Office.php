<?php

namespace App\Models;

use App\Domain\Organization\Enums\OfficeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['parent_id', 'code', 'name', 'status', 'supports_staffing', 'requires_manager'])]
/** Nodo recursivo del organigrama y fuente de jerarquía, dotación y responsabilidad. */
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
        $now = now();

        return $this->memberships()
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now));
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

    /** @return HasOne<OfficeDocumentAccessSetting, $this> */
    public function documentAccessSetting(): HasOne
    {
        return $this->hasOne(OfficeDocumentAccessSetting::class);
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
