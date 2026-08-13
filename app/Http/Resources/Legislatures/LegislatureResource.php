<?php

namespace App\Http\Resources\Legislatures;

use App\Models\Legislature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Legislature */
class LegislatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_year' => $this->start_year,
            'end_year' => $this->end_year,
            'period_label' => $this->period_label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'inactivated_at' => $this->inactivated_at?->toIso8601String(),
            'current_board_assignments' => LegislatureBoardAssignmentResource::collection(
                $this->whenLoaded('currentBoardAssignments'),
            ),
        ];
    }
}
