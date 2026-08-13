<?php

namespace App\Models;

use App\Domain\Legislatures\Enums\BoardPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'legislature_id',
    'user_id',
    'position',
    'effective_on',
    'effective_at',
    'ended_on',
    'ended_at',
])]
class LegislatureBoardAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'position' => BoardPosition::class,
            'effective_on' => 'immutable_date',
            'effective_at' => 'immutable_datetime',
            'ended_on' => 'immutable_date',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Legislature, $this> */
    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
