<?php

namespace App\Http\Resources\Legislatures;

use App\Models\LegislativeBody;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegislativeBody */
class LegislativeBodyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislature_id' => $this->legislature_id,
            'body_type' => $this->body_type->value,
            'body_type_label' => $this->body_type->label(),
            'name' => $this->name,
            'purpose' => $this->purpose,
            'office' => $this->whenLoaded('office', fn () => [
                'id' => $this->office->id,
                'code' => $this->office->code,
                'name' => $this->office->name,
            ]),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'current_memberships' => LegislativeBodyMembershipResource::collection(
                $this->whenLoaded('currentMemberships'),
            ),
            'delegations' => LegislativeDelegationResource::collection(
                $this->whenLoaded('delegations'),
            ),
        ];
    }
}
