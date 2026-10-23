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
        $managerHistoryOfficeIds = $this->relationLoaded('officeManagerAssignments')
            ? $this->officeManagerAssignments->pluck('office_id')->map(fn ($id) => (int) $id)->unique()
            : collect();
        $currentManagerOfficeIds = $this->relationLoaded('officeManagerAssignments')
            ? $this->officeManagerAssignments
                ->filter(fn ($assignment) => $assignment->effective_from->lessThanOrEqualTo(now())
                    && ($assignment->effective_to === null || $assignment->effective_to->isAfter(now()))
                    && $assignment->relationLoaded('officeMembership')
                    && $assignment->officeMembership->effective_from->lessThanOrEqualTo(now())
                    && ($assignment->officeMembership->effective_to === null || $assignment->officeMembership->effective_to->isAfter(now())))
                ->pluck('office_id')->map(fn ($id) => (int) $id)->unique()
            : collect();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'must_change_password' => $this->must_change_password,
            'environment' => [
                'tier' => config('sigal.deployment.tier'),
                'label' => config('sigal.deployment.label'),
            ],
            'features' => [
                'operational_reset' => config('sigal.operational_reset.enabled') === true,
                'uat_mode' => config('sigal.uat.enabled') === true,
                'uat_label' => config('sigal.uat.label'),
            ],
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
                    'is_manager' => $managerHistoryOfficeIds->contains((int) $membership->office_id)
                        ? $currentManagerOfficeIds->contains((int) $membership->office_id)
                        : $membership->membership_role->value === 'manager',
                    'position_title' => $membership->position_title,
                ])
                ->values()),
            'legislative_profiles' => $this->whenLoaded('currentLegislatureMemberships', function () {
                return $this->currentLegislatureMemberships->flatMap(function ($member) {
                    $base = [[
                        'kind' => 'legislature_member',
                        'legislature_member_id' => $member->id,
                        'body_membership_id' => null,
                        'label' => "Asambleísta · {$member->legislature->period_label}",
                        'role' => 'assembly_member',
                        'role_label' => 'Asambleísta',
                        'legislature' => [
                            'id' => $member->legislature->id,
                            'period_label' => $member->legislature->period_label,
                        ],
                        'body' => null,
                    ]];
                    $bodyProfiles = $member->currentBodyMemberships->map(fn ($membership) => [
                        'kind' => 'legislative_body',
                        'legislature_member_id' => $member->id,
                        'body_membership_id' => $membership->id,
                        'label' => "{$membership->body->name} · {$membership->role->label()}",
                        'role' => $membership->role->value,
                        'role_label' => $membership->role->label(),
                        'legislature' => [
                            'id' => $member->legislature->id,
                            'period_label' => $member->legislature->period_label,
                        ],
                        'body' => [
                            'id' => $membership->body->id,
                            'type' => $membership->body->body_type->value,
                            'type_label' => $membership->body->body_type->label(),
                            'name' => $membership->body->name,
                            'office_id' => $membership->body->office_id,
                            'office_code' => $membership->body->office->code,
                        ],
                    ])->all();

                    return [...$base, ...$bodyProfiles];
                })->values();
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
