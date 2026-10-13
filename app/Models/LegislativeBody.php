<?php

namespace App\Models;

use App\Domain\Legislatures\Enums\LegislativeBodyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['legislature_id', 'office_id', 'body_type', 'name', 'purpose', 'effective_from', 'effective_to', 'created_by'])]
/** Bancada o comisión con vigencia propia dentro de una legislatura. */
class LegislativeBody extends Model
{
    protected function casts(): array
    {
        return [
            'body_type' => LegislativeBodyType::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(LegislativeBodyMembership::class);
    }

    public function currentMemberships(): HasMany
    {
        return $this->memberships()
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }

    public function delegations(): HasMany
    {
        return $this->hasMany(LegislativeDelegation::class);
    }
}
