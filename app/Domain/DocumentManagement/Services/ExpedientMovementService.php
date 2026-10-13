<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\UpdateMovementRecipientStatusData;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\InstitutionalWorkflow;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\Legislatures\Enums\LegislativeBodyType;
use App\Domain\Legislatures\Services\LegislativeAuthorizationService;
use App\Models\Expedient;
use App\Models\ExpedientMovement;
use App\Models\ExpedientMovementRecipient;
use App\Models\LegislatureMember;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfiere la tenencia administrativa y mantiene el estado independiente por oficina.
 *
 * La autoridad para derivar se calcula desde el último movimiento, nunca desde el
 * creador del expediente ni desde una oficina responsable histórica.
 */
class ExpedientMovementService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly LegislativeAuthorizationService $legislativeAuthorization,
    ) {}

    public function create(
        Expedient $expedient,
        CreateExpedientMovementData $data,
        User $actor,
        RequestAuditContext $context,
        ?InstitutionalWorkflow $institutionalWorkflow = null,
    ): ExpedientMovement {
        return DB::transaction(function () use ($expedient, $data, $actor, $context, $institutionalWorkflow): ExpedientMovement {
            // Serializa derivaciones concurrentes para que solo una parta de la tenencia vigente.
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanReceiveMovements($target);
            $this->assertDistinctRecipients($data);
            $this->assertInstitutionalWorkflow($target, $institutionalWorkflow);

            $senderOffice = Office::query()->active()->supportingStaffing()->lockForUpdate()->findOrFail($data->senderOfficeId);

            $isLegislativeSender = $this->legislativeAuthorization->isLegislativeOffice($senderOffice->id);
            $hasOfficeMembership = $actor->currentOfficeMemberships()->where('office_id', $senderOffice->id)->exists();
            $canActLegislatively = $this->legislativeAuthorization->canActForOffice(
                $actor,
                $senderOffice->id,
                $context->actingBodyMembershipId,
            );
            $canActAsIndividual = $this->canActAsIndividualHolder($actor, $target, $senderOffice, $context);
            if (($isLegislativeSender && ! $canActLegislatively && ! $canActAsIndividual)
                || (! $isLegislativeSender && ! $hasOfficeMembership)) {
                throw ValidationException::withMessages([
                    'sender_office_id' => $isLegislativeSender
                        ? 'Solo la autoridad vigente del órgano, o su reemplazo temporal, puede derivar en su representación.'
                        : 'El usuario debe pertenecer a la oficina remitente.',
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

            $this->assertLegislativeChannel($senderOffice, $recipientIds, $institutionalWorkflow);
            $this->assertAttentionRecipients($target, $senderOffice, $data);

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

            // Cada destino primario administra su propio pendiente; una copia nunca exige acción.
            foreach ($data->primaryOfficeIds as $officeId) {
                $movement->recipients()->create([
                    'recipient_office_id' => $officeId,
                    'attention_legislature_member_id' => $data->attentionLegislatureMemberByOffice[$officeId] ?? null,
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

            // El estado del expediente es una proyección del último movimiento, no una decisión manual.
            $oldStatus = $target->status;
            $target->update(['status' => $this->calculateExpedientStatus($target)]);

            $this->activityLogger->record(
                event: 'document_management.expedient_movement.created',
                actor: $actor,
                subject: $movement,
                context: $context,
                newValues: [
                    ...$this->movementSnapshot($movement->load(['senderOffice', 'recipients.recipientOffice'])),
                    'institutional_workflow' => $institutionalWorkflow?->value,
                ],
            );

            $this->logStatusChange($target, $oldStatus, $actor, $context);

            return $movement->load(['senderOffice', 'sentBy', 'recipients.recipientOffice', 'recipients.attentionLegislatureMember.user']);
        });
    }

    public function updateRecipientStatus(
        Expedient $expedient,
        ExpedientMovementRecipient $recipient,
        UpdateMovementRecipientStatusData $data,
        User $actor,
        RequestAuditContext $context,
        ?InstitutionalWorkflow $institutionalWorkflow = null,
    ): ExpedientMovementRecipient {
        return DB::transaction(function () use ($expedient, $recipient, $data, $actor, $context, $institutionalWorkflow): ExpedientMovementRecipient {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanReceiveMovements($targetExpedient);
            $this->assertInstitutionalWorkflow($targetExpedient, $institutionalWorkflow);

            $target = ExpedientMovementRecipient::query()
                ->whereHas('movement', fn ($movement) => $movement->where('expedient_id', $targetExpedient->id))
                ->with('movement')
                ->lockForUpdate()
                ->findOrFail($recipient->id);

            $hasOfficeMembership = $actor->currentOfficeMemberships()->where('office_id', $target->recipient_office_id)->exists();
            $canActLegislatively = $this->legislativeAuthorization->canActOnRecipient(
                $actor,
                $target,
                $context->actingLegislatureMemberId,
                $context->actingBodyMembershipId,
            );
            if (! $hasOfficeMembership && ! $canActLegislatively) {
                throw ValidationException::withMessages([
                    'recipient' => 'El usuario no pertenece al equipo administrativo ni representa al destinatario legislativo.',
                ]);
            }

            // Un movimiento anterior queda como historia y ya no puede recuperar el control actual.
            $latestMovementId = $targetExpedient->movements()->latest('sent_at')->latest('id')->value('id');

            if ($target->expedient_movement_id !== $latestMovementId) {
                throw ValidationException::withMessages([
                    'recipient' => 'Solo puede actualizarse la recepción vigente del expediente.',
                ]);
            }

            if ($target->recipient_kind !== MovementRecipientKind::Primary) {
                throw ValidationException::withMessages([
                    'recipient' => 'Las oficinas en copia reciben la información sin una acción pendiente.',
                ]);
            }

            if (! $target->movement->requires_response) {
                throw ValidationException::withMessages([
                    'recipient' => 'Esta derivación es solo informativa y ya fue finalizada automáticamente.',
                ]);
            }

            if (in_array($target->status, [
                MovementRecipientStatus::Responded,
                MovementRecipientStatus::Returned,
                MovementRecipientStatus::Rejected,
                MovementRecipientStatus::Completed,
            ], true)) {
                throw ValidationException::withMessages([
                    'recipient' => 'La participación de esta oficina ya fue finalizada y no puede modificarse.',
                ]);
            }

            if ($data->status === MovementRecipientStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Una recepción pendiente solo se actualiza mediante una acción de la oficina destinataria.',
                ]);
            }

            if (in_array($data->status, [
                MovementRecipientStatus::Responded,
                MovementRecipientStatus::Returned,
                MovementRecipientStatus::Rejected,
                MovementRecipientStatus::Completed,
            ], true) && $institutionalWorkflow !== InstitutionalWorkflow::WarehouseMaterialRequest
                && ($target->attention_legislature_member_id !== null
                || $this->legislativeAuthorization->isLegislativeOffice($target->recipient_office_id))
                && ! $canActLegislatively) {
                throw ValidationException::withMessages([
                    'status' => 'El personal administrativo puede recibir y preparar; la finalización corresponde a la autoridad legislativa vigente.',
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

            return $target->load(['movement', 'recipientOffice', 'attentionLegislatureMember.user', 'receivedBy', 'completedBy']);
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

    private function canActAsIndividualHolder(User $actor, Expedient $expedient, Office $senderOffice, RequestAuditContext $context): bool
    {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return false;
        }

        $recipient = ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovementId)
            ->where('recipient_office_id', $senderOffice->id)
            ->where('recipient_kind', MovementRecipientKind::Primary->value)
            ->whereNotNull('attention_legislature_member_id')
            ->first();

        return $recipient !== null && $this->legislativeAuthorization->canActOnRecipient(
            $actor,
            $recipient,
            $context->actingLegislatureMemberId,
            $context->actingBodyMembershipId,
        );
    }

    /** @param list<int> $recipientIds */
    private function assertLegislativeChannel(Office $senderOffice, array $recipientIds, ?InstitutionalWorkflow $institutionalWorkflow): void
    {
        if ($institutionalWorkflow === InstitutionalWorkflow::WarehouseMaterialRequest) {
            return;
        }

        $secretariat = Office::query()->where('code', 'SGEN')->firstOrFail();
        $legislativeRecipientIds = collect($recipientIds)
            ->filter(fn (int $officeId) => $this->legislativeAuthorization->isLegislativeOffice($officeId));

        if ($legislativeRecipientIds->isNotEmpty() && (int) $senderOffice->id !== (int) $secretariat->id) {
            throw ValidationException::withMessages([
                'recipient_offices' => 'Toda documentación dirigida a bancadas o comisiones debe canalizarse mediante Secretaría General.',
            ]);
        }

        if ($this->legislativeAuthorization->isLegislativeOffice($senderOffice->id)
            && ! collect($recipientIds)->contains(fn (int $officeId) => (int) $officeId === (int) $secretariat->id)) {
            throw ValidationException::withMessages([
                'recipient_offices' => 'Toda salida de una bancada o comisión debe remitirse primero a Secretaría General.',
            ]);
        }

        if ($this->legislativeAuthorization->isLegislativeOffice($senderOffice->id)
            && collect($recipientIds)->contains(fn (int $officeId) => (int) $officeId !== (int) $secretariat->id)) {
            throw ValidationException::withMessages([
                'recipient_offices' => 'Secretaría General debe ser el único destino de esta salida legislativa.',
            ]);
        }
    }

    private function assertInstitutionalWorkflow(Expedient $expedient, ?InstitutionalWorkflow $institutionalWorkflow): void
    {
        if ($institutionalWorkflow === null) {
            return;
        }

        if ($institutionalWorkflow === InstitutionalWorkflow::WarehouseMaterialRequest
            && ! $expedient->expedientType()->where('code', 'WAREHOUSES_INVENTORY')->exists()) {
            throw ValidationException::withMessages([
                'workflow' => 'La excepción de enrutamiento de Almacenes solo puede aplicarse a una solicitud formal de materiales.',
            ]);
        }
    }

    private function assertAttentionRecipients(Expedient $expedient, Office $senderOffice, CreateExpedientMovementData $data): void
    {
        if ($data->attentionLegislatureMemberByOffice === []) {
            return;
        }

        if ($senderOffice->code !== 'SGEN') {
            throw ValidationException::withMessages([
                'primary_recipient_attention' => 'Solo Secretaría General puede distribuir correspondencia a un asambleísta específico.',
            ]);
        }

        foreach ($data->attentionLegislatureMemberByOffice as $officeId => $memberId) {
            if (! in_array($officeId, $data->primaryOfficeIds, true)) {
                throw ValidationException::withMessages([
                    'primary_recipient_attention' => 'La atención personal debe corresponder a una oficina destinataria principal.',
                ]);
            }

            $valid = LegislatureMember::query()
                ->whereKey($memberId)
                ->where('legislature_id', $expedient->legislature_id)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('currentBodyMemberships.body', fn ($body) => $body
                    ->where('office_id', $officeId)
                    ->where('body_type', LegislativeBodyType::Caucus->value))
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages([
                    'primary_recipient_attention' => 'El destinatario específico debe pertenecer actualmente a la bancada seleccionada.',
                ]);
            }
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

    /** Calcula el estado agregado exclusivamente desde los destinatarios del último movimiento. */
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
            'attention_legislature_member_id' => $recipient->attention_legislature_member_id,
            'recipient_kind' => $recipient->recipient_kind->value,
            'status' => $recipient->status->value,
            'action_note' => $recipient->action_note,
            'received_at' => $recipient->received_at?->toIso8601String(),
            'completed_at' => $recipient->completed_at?->toIso8601String(),
        ];
    }
}
