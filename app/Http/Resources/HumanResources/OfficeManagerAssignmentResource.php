<?php

namespace App\Http\Resources\HumanResources;

use App\Models\OfficeManagerAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OfficeManagerAssignment */
class OfficeManagerAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();
        $state = $this->effective_from->isFuture()
            ? 'upcoming'
            : (($this->effective_to === null || $this->effective_to->isAfter($now)) ? 'current' : 'ended');

        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'office_membership_id' => $this->office_membership_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'status' => $this->user->status->value,
            ]),
            'position_title' => $this->whenLoaded('officeMembership', fn () => $this->officeMembership->position_title),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'is_temporary' => $this->effective_to !== null,
            'state' => $state,
            'state_label' => match ($state) {
                'current' => 'Vigente',
                'upcoming' => 'Programada',
                default => 'Finalizada',
            },
            'designation_note' => $this->designation_note,
            'closure_note' => $this->closure_note,
            'designated_by' => $this->whenLoaded('designatedBy', fn () => $this->designatedBy?->only(['id', 'name'])),
            'ended_by' => $this->whenLoaded('endedBy', fn () => $this->endedBy?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
