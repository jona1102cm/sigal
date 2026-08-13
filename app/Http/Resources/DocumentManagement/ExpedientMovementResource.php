<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\ExpedientMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExpedientMovement */
class ExpedientMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expedient_id' => $this->expedient_id,
            'sender_office' => $this->whenLoaded('senderOffice', fn () => [
                'id' => $this->senderOffice->id,
                'code' => $this->senderOffice->code,
                'name' => $this->senderOffice->name,
            ]),
            'instruction' => $this->instruction,
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'due_on' => $this->due_on?->toDateString(),
            'requires_response' => $this->requires_response,
            'requires_response_label' => $this->requires_response ? 'Requiere respuesta' : 'Solo informativo',
            'sent_at' => $this->sent_at->toIso8601String(),
            'sent_by' => $this->whenLoaded('sentBy', fn () => [
                'id' => $this->sentBy->id,
                'name' => $this->sentBy->name,
            ]),
            'recipients' => ExpedientMovementRecipientResource::collection($this->whenLoaded('recipients')),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'title' => $document->title,
            ])->values()),
        ];
    }
}
