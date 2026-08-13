<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['legislature_id', 'last_issued_number'])]
/** Contador SIGAL por legislatura, reservado bajo bloqueo para evitar duplicados. */
class InstitutionalSequence extends Model
{
    /** @return BelongsTo<Legislature, $this> */
    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class);
    }
}
