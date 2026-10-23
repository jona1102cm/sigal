<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['expedient_movement_recipient_id', 'user_id', 'first_opened_at'])]
/** Acuse individual de primera apertura de una llegada documental. */
class ExpedientRecipientRead extends Model
{
    protected function casts(): array
    {
        return ['first_opened_at' => 'immutable_datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(ExpedientMovementRecipient::class, 'expedient_movement_recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
