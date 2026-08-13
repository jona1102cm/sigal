<?php

namespace App\Http\Resources\Organization;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Office */
class OfficeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => [
                'code' => $this->parent->code,
                'name' => $this->parent->name,
            ]),
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'supports_staffing' => $this->supports_staffing,
            'requires_manager' => $this->requires_manager,
            'organizational_profile_label' => $this->organizationalProfileLabel(),
            'current_memberships' => OfficeMembershipResource::collection(
                $this->whenLoaded('currentMemberships'),
            ),
        ];
    }
}
