<?php

namespace App\Http\Resources\HumanResources;

use App\Models\OfficePosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OfficePosition */
class OfficePositionResource extends JsonResource
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
            'name' => $this->name,
            'membership_role' => $this->membership_role->value,
            'membership_role_label' => $this->membership_role->label(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
