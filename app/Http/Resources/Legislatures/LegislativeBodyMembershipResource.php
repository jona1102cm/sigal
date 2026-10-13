<?php

namespace App\Http\Resources\Legislatures;

use App\Models\LegislativeBodyMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegislativeBodyMembership */
class LegislativeBodyMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislative_body_id' => $this->legislative_body_id,
            'legislature_member_id' => $this->legislature_member_id,
            'member' => $this->whenLoaded('legislatureMember', fn () => [
                'id' => $this->legislatureMember->id,
                'user_id' => $this->legislatureMember->user_id,
                'name' => $this->legislatureMember->relationLoaded('user') ? $this->legislatureMember->user->name : null,
            ]),
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
        ];
    }
}
