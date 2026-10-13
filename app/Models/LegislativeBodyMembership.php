<?php

namespace App\Models;

use App\Domain\Legislatures\Enums\LegislativeBodyRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['legislative_body_id', 'legislature_member_id', 'role', 'effective_from', 'effective_to', 'assigned_by'])]
/** Cargo histórico de un asambleísta dentro de una bancada o comisión. */
class LegislativeBodyMembership extends Model
{
    protected function casts(): array
    {
        return [
            'role' => LegislativeBodyRole::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function body(): BelongsTo
    {
        return $this->belongsTo(LegislativeBody::class, 'legislative_body_id');
    }

    public function legislatureMember(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function delegationsAsPrincipal(): HasMany
    {
        return $this->hasMany(LegislativeDelegation::class, 'principal_membership_id');
    }

    public function delegationsAsDelegate(): HasMany
    {
        return $this->hasMany(LegislativeDelegation::class, 'delegate_membership_id');
    }
}
