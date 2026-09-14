<?php

namespace App\Http\Resources\Users;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'must_change_password' => $this->must_change_password,
            'permissions' => $this->permissionCodes(),
            'roles' => $this->whenLoaded('currentRoleAssignments', fn () => $this->currentRoleAssignments
                ->map(fn ($assignment) => [
                    'code' => $assignment->role->code,
                    'name' => $assignment->role->name,
                    'effective_from' => $assignment->effective_from->toIso8601String(),
                ])
                ->values()),
            'office_memberships' => $this->whenLoaded('currentOfficeMemberships', fn () => $this->currentOfficeMemberships
                ->map(fn ($membership) => [
                    'office_id' => $membership->office_id,
                    'office' => $membership->relationLoaded('office') ? [
                        'code' => $membership->office->code,
                        'name' => $membership->office->name,
                    ] : null,
                    'membership_role' => $membership->membership_role->value,
                    'membership_role_label' => $membership->membership_role->label(),
                    'position_title' => $membership->position_title,
                ])
                ->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
