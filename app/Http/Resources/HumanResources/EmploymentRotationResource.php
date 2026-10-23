<?php

namespace App\Http\Resources\HumanResources;

use App\Models\EmploymentRotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmploymentRotation */
class EmploymentRotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'reason' => $this->reason,
            'memorandum_number' => $this->memorandum_number,
            'memorandum_date' => $this->memorandum_date?->toDateString(),
            'source_assignment' => $this->whenLoaded('sourceOfficeMembership', fn () => [
                'office' => $this->sourceOfficeMembership->relationLoaded('office') ? [
                    'id' => $this->sourceOfficeMembership->office->id,
                    'code' => $this->sourceOfficeMembership->office->code,
                    'name' => $this->sourceOfficeMembership->office->name,
                ] : null,
                'position_title' => $this->sourceOfficeMembership->position_title,
            ]),
            'destination_position' => new OfficePositionResource($this->whenLoaded('destinationOfficePosition')),
            'attachment' => new EmploymentRotationAttachmentResource($this->whenLoaded('attachment')),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
