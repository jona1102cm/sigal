<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['material_request_id', 'action', 'from_status', 'to_status', 'from_stage', 'to_stage', 'actor_id', 'office_id', 'notes', 'metadata', 'decided_at'])]
class MaterialRequestDecision extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'decided_at' => 'immutable_datetime'];
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
