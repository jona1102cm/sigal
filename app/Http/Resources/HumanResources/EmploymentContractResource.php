<?php

namespace App\Http\Resources\HumanResources;

use App\Models\EmploymentContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmploymentContract */
class EmploymentContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isUpcoming = $this->ended_at === null && $this->starts_on->isAfter(today());
        $isExpired = $this->ended_at === null && $this->ends_on !== null && $this->ends_on->isBefore(today());

        return [
            'id' => $this->id,
            'contract_type' => $this->contract_type->value,
            'contract_type_label' => $this->contract_type->label(),
            'contract_amount' => $this->contract_amount,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'state' => $this->ended_at !== null ? 'finished' : ($isUpcoming ? 'upcoming' : ($isExpired ? 'expired' : 'current')),
            'state_label' => $this->ended_at !== null ? 'Finalizado' : ($isUpcoming ? 'Programado' : ($isExpired ? 'Pendiente de cierre' : 'Vigente')),
            'office_position' => new OfficePositionResource($this->whenLoaded('officePosition')),
            'current_assignment' => $this->whenLoaded('currentOfficeMembership', function (): ?array {
                if ($this->currentOfficeMembership === null) {
                    return null;
                }

                return [
                    'id' => $this->currentOfficeMembership->id,
                    'office' => $this->currentOfficeMembership->relationLoaded('office') ? [
                        'id' => $this->currentOfficeMembership->office->id,
                        'code' => $this->currentOfficeMembership->office->code,
                        'name' => $this->currentOfficeMembership->office->name,
                    ] : null,
                    'position' => $this->currentOfficeMembership->relationLoaded('officePosition') ? [
                        'id' => $this->currentOfficeMembership->officePosition?->id,
                        'name' => $this->currentOfficeMembership->officePosition?->name,
                        'membership_role' => $this->currentOfficeMembership->officePosition?->membership_role->value,
                        'membership_role_label' => $this->currentOfficeMembership->officePosition?->membership_role->label(),
                    ] : null,
                    'position_title' => $this->currentOfficeMembership->position_title,
                    'membership_role' => $this->currentOfficeMembership->membership_role->value,
                    'effective_from' => $this->currentOfficeMembership->effective_from->toIso8601String(),
                ];
            }),
            'rotations' => EmploymentRotationResource::collection($this->whenLoaded('rotations')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
