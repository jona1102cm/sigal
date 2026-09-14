<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\ExpedientMovementRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExpedientMovementRecipient */
class ExpedientMovementRecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient_office' => $this->whenLoaded('recipientOffice', fn () => [
                'id' => $this->recipientOffice->id,
                'code' => $this->recipientOffice->code,
                'name' => $this->recipientOffice->name,
            ]),
            'recipient_kind' => $this->recipient_kind->value,
            'recipient_kind_label' => $this->recipient_kind->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'action_note' => $this->action_note,
            'received_at' => $this->received_at?->toIso8601String(),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy === null ? null : [
                'id' => $this->receivedBy->id,
                'name' => $this->receivedBy->name,
            ]),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy === null ? null : [
                'id' => $this->completedBy->id,
                'name' => $this->completedBy->name,
            ]),
            'internal_assignments' => $this->whenLoaded('internalAssignments', fn () => $this->internalAssignments
                ->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'name' => $assignment->user->name,
                    'assignment_role' => $assignment->assignment_role->value,
                    'assignment_role_label' => $assignment->assignment_role->label(),
                    'assigned_by' => $assignment->assignedBy === null ? null : [
                        'id' => $assignment->assignedBy->id,
                        'name' => $assignment->assignedBy->name,
                    ],
                    'effective_from' => $assignment->effective_from->toIso8601String(),
                    'effective_to' => $assignment->effective_to?->toIso8601String(),
                    'is_current' => $assignment->effective_to === null || $assignment->effective_to->isFuture(),
                ])->values()),
        ];
    }
}
