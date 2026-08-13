<?php

namespace App\Http\Resources\DocumentManagement;

use App\Domain\DocumentManagement\Services\RichTextSanitizer;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_initial' => $this->is_initial,
            'title' => $this->title,
            'content' => app(RichTextSanitizer::class)->sanitize($this->content),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'version_number' => $this->version_number,
            'origin_number' => $this->origin_number,
            'origin_date' => $this->origin_date?->toDateString(),
            'office_reference' => $this->office_reference,
            'formatted_number' => $this->whenLoaded('numberSeries', fn () => $this->numberSeries?->formatted_number),
            'document_type' => $this->whenLoaded('documentType', fn () => [
                'id' => $this->documentType->id,
                'code' => $this->documentType->code,
                'name' => $this->documentType->name,
                'is_official' => $this->documentType->is_official,
            ]),
            'issuing_office' => $this->whenLoaded('issuingOffice', fn () => $this->issuingOffice === null ? null : [
                'id' => $this->issuingOffice->id,
                'code' => $this->issuingOffice->code,
                'name' => $this->issuingOffice->name,
            ]),
            'supersedes_document_id' => $this->supersedes_document_id,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'attachments' => DocumentAttachmentResource::collection($this->whenLoaded('attachments')),
            'movement_routes' => $this->whenLoaded('movements', fn () => $this->movements
                ->sortByDesc('id')
                ->map(fn ($movement) => [
                    'id' => $movement->id,
                    'sender_office' => $movement->senderOffice === null ? null : [
                        'id' => $movement->senderOffice->id,
                        'code' => $movement->senderOffice->code,
                        'name' => $movement->senderOffice->name,
                    ],
                    'recipients' => $movement->recipients->map(fn ($recipient) => [
                        'recipient_kind' => $recipient->recipient_kind->value,
                        'recipient_office' => $recipient->recipientOffice === null ? null : [
                            'id' => $recipient->recipientOffice->id,
                            'code' => $recipient->recipientOffice->code,
                            'name' => $recipient->recipientOffice->name,
                        ],
                    ])->values(),
                ])
                ->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
