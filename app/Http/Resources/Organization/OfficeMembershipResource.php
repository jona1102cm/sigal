<?php

namespace App\Http\Resources\Organization;

use App\Models\OfficeMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OfficeMembership */
class OfficeMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'office' => $this->whenLoaded('office', fn () => [
                'code' => $this->office->code,
                'name' => $this->office->name,
            ]),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'membership_role' => $this->membership_role->value,
            'membership_role_label' => $this->membership_role->label(),
            'position_title' => $this->position_title,
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
        ];
    }
}
