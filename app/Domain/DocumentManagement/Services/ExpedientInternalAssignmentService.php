<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\Enums\InternalAssignmentRole;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\DocumentManagement\Enums\OfficeDocumentAccessMode;
use App\Models\Expedient;
use App\Models\ExpedientInternalAssignment;
use App\Models\ExpedientMovementRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Registra el reparto interno sin alterar la ruta oficial entre oficinas. */
class ExpedientInternalAssignmentService
{
    public function __construct(
        private readonly OfficeDocumentAccessService $officeAccess,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @param list<int> $collaboratorUserIds */
    public function replace(
        Expedient $expedient,
        ExpedientMovementRecipient $recipient,
        int $responsibleUserId,
        array $collaboratorUserIds,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientMovementRecipient {
        return DB::transaction(function () use ($expedient, $recipient, $responsibleUserId, $collaboratorUserIds, $actor, $context): ExpedientMovementRecipient {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $target = ExpedientMovementRecipient::query()
                ->with(['movement', 'recipientOffice.documentAccessSetting'])
                ->whereHas('movement', fn ($movement) => $movement->where('expedient_id', $targetExpedient->id))
                ->lockForUpdate()
                ->findOrFail($recipient->id);

            $latestMovementId = $targetExpedient->movements()->latest('sent_at')->latest('id')->value('id');
            if ((int) $target->expedient_movement_id !== (int) $latestMovementId
                || $target->recipient_kind !== MovementRecipientKind::Primary
                || in_array($target->status, [
                    MovementRecipientStatus::Responded,
                    MovementRecipientStatus::Returned,
                    MovementRecipientStatus::Rejected,
                    MovementRecipientStatus::Completed,
                ], true)) {
                throw ValidationException::withMessages([
                    'recipient' => 'Solo puede asignarse la recepción principal vigente de la oficina.',
                ]);
            }

            if ($this->officeAccess->modeFor($target->recipientOffice) !== OfficeDocumentAccessMode::ManagerAssignment) {
                throw ValidationException::withMessages([
                    'recipient' => 'La asignación individual no está activa para esta oficina.',
                ]);
            }

            if (! $actor->isSuperAdministrator() && ! $actor->isCurrentManagerOfOffice($target->recipient_office_id)) {
                throw ValidationException::withMessages(['recipient' => 'Solo la jefatura vigente puede distribuir este ingreso.']);
            }

            $collaborators = collect($collaboratorUserIds)->map(fn ($id) => (int) $id)->unique()->values();
            if ($collaborators->contains($responsibleUserId)) {
                throw ValidationException::withMessages([
                    'collaborator_user_ids' => 'El responsable operativo no puede repetirse como colaborador.',
                ]);
            }

            $userIds = collect([$responsibleUserId])->merge($collaborators)->unique()->values();
            $validCount = User::query()
                ->whereIn('id', $userIds)
                ->where('status', 'active')
                ->whereHas('currentOfficeMemberships', fn ($membership) => $membership
                    ->where('office_id', $target->recipient_office_id))
                ->count();

            if ($validCount !== $userIds->count()) {
                throw ValidationException::withMessages([
                    'responsible_user_id' => 'Responsable y colaboradores deben ser miembros activos y vigentes de la oficina.',
                ]);
            }

            $current = $target->currentInternalAssignments()->lockForUpdate()->get();
            $oldValues = ['assignments' => $this->snapshot($current)];
            $now = now();
            $current->each->update(['effective_to' => $now]);

            ExpedientInternalAssignment::query()->create([
                'expedient_movement_recipient_id' => $target->id,
                'user_id' => $responsibleUserId,
                'assignment_role' => InternalAssignmentRole::Responsible,
                'assigned_by' => $actor->id,
                'effective_from' => $now,
            ]);

            foreach ($collaborators as $userId) {
                ExpedientInternalAssignment::query()->create([
                    'expedient_movement_recipient_id' => $target->id,
                    'user_id' => $userId,
                    'assignment_role' => InternalAssignmentRole::Collaborator,
                    'assigned_by' => $actor->id,
                    'effective_from' => $now,
                ]);
            }

            $target->load(['currentInternalAssignments.user', 'recipientOffice']);
            $this->activityLogger->record(
                event: 'document_management.internal_assignment.replaced',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: ['assignments' => $this->snapshot($target->currentInternalAssignments)],
            );

            return $target;
        });
    }

    /** @param iterable<int, ExpedientInternalAssignment> $assignments @return list<array<string, mixed>> */
    private function snapshot(iterable $assignments): array
    {
        return collect($assignments)->map(fn (ExpedientInternalAssignment $assignment) => [
            'user_id' => $assignment->user_id,
            'role' => $assignment->assignment_role->value,
            'effective_from' => $assignment->effective_from->toIso8601String(),
            'effective_to' => $assignment->effective_to?->toIso8601String(),
        ])->values()->all();
    }
}
