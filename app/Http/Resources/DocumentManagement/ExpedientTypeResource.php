<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\ExpedientType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExpedientType */
class ExpedientTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
