<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\UpdateMovementRecipientStatusData;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Models\Expedient;
use App\Models\ExpedientMovement;
use App\Models\ExpedientMovementRecipient;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpedientMovementService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function create(
        Expedient $expedient,
        CreateExpedientMovementData $data,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientMovement {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): ExpedientMovement {
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanReceiveMovements($target);
            $this->assertDistinctRecipients($data);

            $senderOffice = Office::query()->active()->supportingStaffing()->lockForUpdate()->findOrFail($data->senderOfficeId);

            if (! $actor->currentOfficeMemberships()
                ->where('office_id', $senderOffice->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'sender_office_id' => 'El usuario debe pertenecer a la oficina remitente.',
                ]);
            }

            if (! $target->isCurrentlyHeldByOffice($senderOffice->id)) {
                throw ValidationException::withMessages([
                    'sender_office_id' => 'Solo la oficina que actualmente tiene el expediente puede realizar una nueva derivación.',
                ]);
            }

            $recipientIds = [...$data->primaryOfficeIds, ...$data->copyOfficeIds];

            if (Office::query()->active()->supportingStaffing()->whereIn('id', $recipientIds)->count() !== count($recipientIds)) {
                throw ValidationException::withMessages([
                    'recipient_offices' => 'Todas las oficinas destinatarias deben estar activas y admitir funcionarios.',
                ]);
            }

            $movement = ExpedientMovement::query()->create([
                'expedient_id' => $target->id,
                'sender_office_id' => $senderOffice->id,
                'instruction' => $this->inheritedInstruction($target),
                'priority' => $target->priority,
                'due_on' => $target->due_on,
                'requires_response' => $data->requiresResponse,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            foreach ($data->primaryOfficeIds as $officeId) {
                $movement->recipients()->create([
                    'recipient_office_id' => $officeId,
                    'recipient_kind' => MovementRecipientKind::Primary,
                    'status' => $data->requiresResponse
                        ? MovementRecipientStatus::Pending
                        : MovementRecipientStatus::Completed,
                    'completed_by' => $data->requiresResponse ? null : $actor->id,
                    'completed_at' => $data->requiresResponse ? null : now(),
                ]);
            }

            foreach ($data->copyOfficeIds as $officeId) {
                $movement->recipients()->create([
                    'recipient_office_id' => $officeId,
                    'recipient_kind' => MovementRecipientKind::Copy,
                    'status' => MovementRecipientStatus::Completed,
                    'completed_by' => $actor->id,
                    'completed_at' => now(),
                ]);
            }

            $oldStatus = $target->status;
            $target->update(['status' => $this->calculateExpedientStatus($target)]);

            $this->activityLogger->record(
                event: 'document_management.expedient_movement.created',
                actor: $actor,
                subject: $movement,
                context: $context,
                newValues: $this->movementSnapshot($movement->load(['senderOffice', 'recipients.recipientOffice'])),
            );

            $this->logStatusChange($target, $oldStatus, $actor, $context);

            return $movement->load(['senderOffice', 'sentBy', 'recipients.recipientOffice']);
        });
    }

    public function updateRecipientStatus(
        Expedient $expedient,
        ExpedientMovementRecipient $recipient,
        UpdateMovementRecipientStatusData $data,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientMovementRecipient {
        return DB::transaction(function () use ($expedient, $recipient, $data, $actor, $context): ExpedientMovementRecipient {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanReceiveMovements($targetExpedient);

            $target = ExpedientMovementRecipient::query()
                ->whereHas('movement', fn ($movement) => $movement->where('expedient_id', $targetExpedient->id))
                ->with('movement')
                ->lockForUpdate()
                ->findOrFail($recipient->id);

            if (! $actor->currentOfficeMemberships()
                ->where('office_id', $target->recipient_office_id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'recipient' => 'El usuario debe pertenecer a la oficina destinataria.',
                ]);
            }

            $latestMovementId = $targetExpedient->movements()->latest('sent_at')->latest('id')->value('id');

            if ($target->expedient_movement_id !== $latestMovementId) {
                throw ValidationException::withMessages([
                    'recipient' => 'Solo puede actualizarse la recepción vigente del expediente.',
                ]);
            }

            if ($target->recipient_kind !== MovementRecipientKind::Primary) {
                throw ValidationException::withMessages([
                    'recipient' => 'Las oficinas en copia reciben la informaciÃ³n sin una acciÃ³n pendiente.',
                ]);
            }

            if (! $target->movement->requires_response) {
                throw ValidationException::withMessages([
                    'recipient' => 'Esta derivaciÃ³n es solo informativa y ya fue finalizada automÃ¡ticamente.',
                ]);
            }

            if (in_array($target->status, [
                MovementRecipientStatus::Responded,
                MovementRecipientStatus::Returned,
                MovementRecipientStatus::Rejected,
                MovementRecipientStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'recipient' => 'La participaciÃ³n de esta oficina ya fue finalizada y no puede modificarse.',
                ]);
            }

            if ($data->status === MovementRecipientStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Una recepción pendiente solo se actualiza mediante una acción de la oficina destinataria.',
                ]);
            }

            $oldValues = $this->recipientSnapshot($target);
            $attributes = [
                'status' => $data->status,
                'action_note' => $data->actionNote,
            ];

            if ($data->status === MovementRecipientStatus::Received && $target->received_at === null) {
                $attributes['received_by'] = $actor->id;
                $attributes['received_at'] = now();
            }

            if (in_array($data->status, [
                MovementRecipientStatus::Responded,
                MovementRecipientStatus::Returned,
                MovementRecipientStatus::Rejected,
                MovementRecipientStatus::Completed,
            ], true)) {
                $attributes['completed_by'] = $actor->id;
                $attributes['completed_at'] = now();
            }

            $target->update($attributes);

            $this->activityLogger->record(
                event: 'document_management.expedient_movement_recipient.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->recipientSnapshot($target),
            );

            $oldStatus = $targetExpedient->status;
            $targetExpedient->update([
                'status' => $this->calculateExpedientStatus($targetExpedient),
            ]);
            $this->logStatusChange($targetExpedient, $oldStatus, $actor, $context);

            return $target->load(['movement', 'recipientOffice', 'receivedBy', 'completedBy']);
        });
    }

    private function assertCanReceiveMovements(Expedient $expedient): void
    {
        if (in_array($expedient->status, [ExpedientStatus::Archived, ExpedientStatus::Closed, ExpedientStatus::Voided], true)) {
            throw ValidationException::withMessages([
                'expedient' => 'No se pueden realizar movimientos en un expediente archivado, cerrado o anulado.',
            ]);
        }
    }

    private function assertDistinctRecipients(CreateExpedientMovementData $data): void
    {
        $recipients = [...$data->primaryOfficeIds, ...$data->copyOfficeIds];

        if (count($recipients) !== count(array_unique($recipients))) {
            throw ValidationException::withMessages([
                'recipient_offices' => 'Una oficina solo puede figurar una vez en la misma derivación.',
            ]);
        }
    }

    private function inheritedInstruction(Expedient $expedient): ?string
    {
        $instruction = trim((string) $expedient->observations);

        if ($instruction !== '') {
            return $instruction;
        }

        return $expedient->movements()
            ->latest('sent_at')
            ->latest('id')
            ->value('instruction');
    }

    private function calculateExpedientStatus(Expedient $expedient): ExpedientStatus
    {
        $latestMovementId = $expedient->movements()
            ->latest('sent_at')
            ->latest('id')
            ->value('id');

        if ($latestMovementId === null) {
            return ExpedientStatus::Registered;
        }

        $statuses = ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovementId)
            ->pluck('status')
            ->map(fn ($status) => $status instanceof MovementRecipientStatus
                ? $status
                : MovementRecipientStatus::from($status));

        if ($statuses->contains(MovementRecipientStatus::Returned) || $statuses->contains(MovementRecipientStatus::Rejected)) {
            return ExpedientStatus::Observed;
        }

        $completedCount = $statuses->filter(fn (MovementRecipientStatus $status) => in_array($status, [
            MovementRecipientStatus::Responded,
            MovementRecipientStatus::Completed,
        ], true))->count();

        if ($completedCount === $statuses->count()) {
            return ExpedientStatus::FullyResponded;
        }

        if ($completedCount > 0) {
            return ExpedientStatus::PartiallyResponded;
        }

        if ($statuses->contains(MovementRecipientStatus::InProcess)) {
            return ExpedientStatus::InProcess;
        }

        if ($statuses->contains(MovementRecipientStatus::Received)) {
            return ExpedientStatus::PendingResponse;
        }

        return ExpedientStatus::Derived;
    }

    private function logStatusChange(Expedient $expedient, ExpedientStatus $oldStatus, User $actor, RequestAuditContext $context): void
    {
        if ($oldStatus === $expedient->status) {
            return;
        }

        $this->activityLogger->record(
            event: 'document_management.expedient.status_recalculated',
            actor: $actor,
            subject: $expedient,
            context: $context,
            oldValues: ['status' => $oldStatus->value],
            newValues: ['status' => $expedient->status->value],
        );
    }

    /** @return array<string, mixed> */
    private function movementSnapshot(ExpedientMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'expedient_id' => $movement->expedient_id,
            'sender_office_id' => $movement->sender_office_id,
            'instruction' => $movement->instruction,
            'priority' => $movement->priority,
            'due_on' => $movement->due_on?->toDateString(),
            'requires_response' => $movement->requires_response,
            'sent_at' => $movement->sent_at->toIso8601String(),
            'recipients' => $movement->recipients->map(fn (ExpedientMovementRecipient $recipient) => [
                'office_id' => $recipient->recipient_office_id,
                'kind' => $recipient->recipient_kind->value,
                'status' => $recipient->status->value,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function recipientSnapshot(ExpedientMovementRecipient $recipient): array
    {
        return [
            'id' => $recipient->id,
            'movement_id' => $recipient->expedient_movement_id,
            'recipient_office_id' => $recipient->recipient_office_id,
            'recipient_kind' => $recipient->recipient_kind->value,
            'status' => $recipient->status->value,
            'action_note' => $recipient->action_note,
            'received_at' => $recipient->received_at?->toIso8601String(),
            'completed_at' => $recipient->completed_at?->toIso8601String(),
        ];
    }
}
