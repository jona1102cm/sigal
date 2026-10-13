<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['legislature_id', 'expedient_id', 'secretariat_office_id', 'referred_by', 'referred_at', 'concluded_at', 'concluded_by'])]
/** Puesta en consideración del Pleno sin transferir la custodia de Secretaría General. */
class PlenaryReferral extends Model
{
    protected function casts(): array
    {
        return [
            'referred_at' => 'immutable_datetime',
            'concluded_at' => 'immutable_datetime',
        ];
    }

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class);
    }

    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    public function secretariatOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'secretariat_office_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function concludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluded_by');
    }
}
