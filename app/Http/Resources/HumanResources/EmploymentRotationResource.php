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
            'position_assignment_mode' => $this->position_assignment_mode->value,
            'position_assignment_mode_label' => $this->position_assignment_mode->label(),
            'source_assignment' => $this->whenLoaded('sourceOfficeMembership', fn () => [
                'office' => $this->sourceOfficeMembership->relationLoaded('office') ? [
                    'id' => $this->sourceOfficeMembership->office->id,
                    'code' => $this->sourceOfficeMembership->office->code,
                    'name' => $this->sourceOfficeMembership->office->name,
                ] : null,
                'position_title' => $this->sourceOfficeMembership->position_title,
            ]),
            'destination_assignment' => [
                'office' => $this->whenLoaded('destinationOffice', fn () => [
                    'id' => $this->destinationOffice->id,
                    'code' => $this->destinationOffice->code,
                    'name' => $this->destinationOffice->name,
                ]),
                'office_position_id' => $this->destination_office_position_id,
                'position_title' => $this->destination_position_title,
                'membership_role' => $this->destination_membership_role->value,
                'membership_role_label' => $this->destination_membership_role->label(),
            ],
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
