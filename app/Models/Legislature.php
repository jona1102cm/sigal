<?php

namespace App\Models;

use App\Domain\Legislatures\Enums\LegislatureStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['start_year', 'end_year', 'status', 'activated_at', 'inactivated_at'])]
class Legislature extends Model
{
    protected function casts(): array
    {
        return [
            'status' => LegislatureStatus::class,
            'activated_at' => 'immutable_datetime',
            'inactivated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<LegislatureBoardAssignment, $this> */
    public function boardAssignments(): HasMany
    {
        return $this->hasMany(LegislatureBoardAssignment::class);
    }

    /** @return HasMany<LegislatureBoardAssignment, $this> */
    public function currentBoardAssignments(): HasMany
    {
        return $this->boardAssignments()->whereNull('ended_at');
    }

    /** @param Builder<Legislature> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', LegislatureStatus::Active->value);
    }

    public function getPeriodLabelAttribute(): string
    {
        return sprintf('%d-%d', $this->start_year, $this->end_year);
    }
}
