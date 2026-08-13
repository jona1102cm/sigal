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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
