<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['office_document_access_setting_id', 'user_id', 'authorized_by', 'effective_from', 'effective_to'])]
/** Miembro que recibe acceso automático mientras la oficina usa equipo autorizado. */
class OfficeDocumentAccessAuthorization extends Model
{
    protected function casts(): array
    {
        return ['effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime'];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(OfficeDocumentAccessSetting::class, 'office_document_access_setting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
