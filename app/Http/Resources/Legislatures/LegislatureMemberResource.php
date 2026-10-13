<?php

namespace App\Http\Resources\Legislatures;

use App\Models\LegislatureMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegislatureMember */
class LegislatureMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislature_id' => $this->legislature_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'body_memberships' => LegislativeBodyMembershipResource::collection(
                $this->whenLoaded('currentBodyMemberships'),
            ),
        ];
    }
}
