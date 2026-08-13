<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\ExpedientReopeningRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExpedientReopeningRequest */
class ExpedientReopeningRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expedient_id' => $this->expedient_id,
            'justification' => $this->justification,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'requested_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
