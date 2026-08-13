<?php

namespace App\Http\Resources\Users;

use App\Models\UserRoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserRoleAssignment */
class UserRoleAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'role' => $this->whenLoaded('role', fn () => [
                'code' => $this->role->code,
                'name' => $this->role->name,
            ]),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
        ];
    }
}
