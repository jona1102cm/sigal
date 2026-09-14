<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\OfficeDocumentAccessMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['office_id', 'mode', 'configured_by'])]
/** Preferencia persistente que una jefatura aplica a todos los ingresos futuros. */
class OfficeDocumentAccessSetting extends Model
{
    protected function casts(): array
    {
        return ['mode' => OfficeDocumentAccessMode::class];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(OfficeDocumentAccessAuthorization::class);
    }

    public function currentAuthorizations(): HasMany
    {
        return $this->authorizations()
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }
}
