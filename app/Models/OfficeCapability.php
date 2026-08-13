<?php

namespace App\Models;

use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['office_id', 'capability'])]
/** Facultad especial de una oficina para acciones sensibles del ciclo documental. */
class OfficeCapability extends Model
{
    protected function casts(): array
    {
        return [
            'capability' => OfficeCapabilityCode::class,
        ];
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
