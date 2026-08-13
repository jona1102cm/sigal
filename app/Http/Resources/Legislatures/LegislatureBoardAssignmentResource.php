<?php

namespace App\Http\Resources\Legislatures;

use App\Models\LegislatureBoardAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegislatureBoardAssignment */
class LegislatureBoardAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislature_id' => $this->legislature_id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'position' => $this->position->value,
            'position_label' => $this->position->label(),
            'effective_on' => $this->effective_on->toDateString(),
            'effective_at' => $this->effective_at->toIso8601String(),
            'ended_on' => $this->ended_on?->toDateString(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
