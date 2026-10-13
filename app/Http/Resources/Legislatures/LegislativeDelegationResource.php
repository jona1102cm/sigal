<?php

namespace App\Http\Resources\Legislatures;

use App\Models\LegislativeDelegation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegislativeDelegation */
class LegislativeDelegationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislative_body_id' => $this->legislative_body_id,
            'principal' => $this->whenLoaded('principalMembership', fn () => [
                'membership_id' => $this->principalMembership->id,
                'role' => $this->principalMembership->role->value,
                'role_label' => $this->principalMembership->role->label(),
                'name' => $this->principalMembership->legislatureMember->user->name,
            ]),
            'delegate' => $this->whenLoaded('delegateMembership', fn () => [
                'membership_id' => $this->delegateMembership->id,
                'role' => $this->delegateMembership->role->value,
                'role_label' => $this->delegateMembership->role->label(),
                'name' => $this->delegateMembership->legislatureMember->user->name,
            ]),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
