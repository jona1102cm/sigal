<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\LifecycleActionData;
use App\Domain\DocumentManagement\DTOs\ReopeningDecisionData;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Domain\DocumentManagement\Enums\ReopeningRequestStatus;
use App\Models\Expedient;
use App\Models\ExpedientMovementRecipient;
use App\Models\ExpedientReopeningRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ejecuta transiciones terminales y el procedimiento controlado de reapertura.
 *
 * Archivo, cierre y anulación requieren facultades específicas; la reapertura deja
 * solicitud y decisión auditables en vez de modificar silenciosamente el estado.
 */
class ExpedientLifecycleService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function archive(Expedient $expedient, LifecycleActionData $data, User $actor, RequestAuditContext $context): Expedient
    {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): Expedient {
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanManageLifecycle($target, $actor, OfficeCapabilityCode::ArchiveExpedients);
            $this->assertAllRecipientsFinished($target);

            if (in_array($target->status, [ExpedientStatus::Archived, ExpedientStatus::Closed, ExpedientStatus::Voided], true)) {
                throw ValidationException::withMessages(['expedient' => 'El expediente no puede archivarse en su estado actual.']);
            }

            $oldValues = $this->snapshot($target);
            $target->update([
                'status' => ExpedientStatus::Archived,
                'archived_at' => now(),
                'archived_by' => $actor->id,
            ]);

            $this->recordAction('archived', $target, $actor, $context, $oldValues, $data->reason);

            return $target;
        });
    }

    public function close(Expedient $expedient, LifecycleActionData $data, User $actor, RequestAuditContext $context): Expedient
    {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): Expedient {
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanManageLifecycle($target, $actor, OfficeCapabilityCode::CloseExpedients);

            if ($target->status !== ExpedientStatus::Archived) {
                throw ValidationException::withMessages(['expedient' => 'Solo se puede cerrar un expediente archivado.']);
            }

            $oldValues = $this->snapshot($target);
            $target->update([
                'status' => ExpedientStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            $this->recordAction('closed', $target, $actor, $context, $oldValues, $data->reason);

            return $target;
        });
    }

    public function void(Expedient $expedient, LifecycleActionData $data, User $actor, RequestAuditContext $context): Expedient
    {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): Expedient {
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertCanManageLifecycle($target, $actor, OfficeCapabilityCode::VoidExpedients);

            if (in_array($target->status, [ExpedientStatus::Closed, ExpedientStatus::Voided], true)) {
                throw ValidationException::withMessages(['expedient' => 'El expediente no puede anularse en su estado actual.']);
            }

            $oldValues = $this->snapshot($target);
            $target->update([
                'status' => ExpedientStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $actor->id,
            ]);

            $this->recordAction('voided', $target, $actor, $context, $oldValues, $data->reason);

            return $target;
        });
    }

    public function requestReopening(Expedient $expedient, LifecycleActionData $data, User $actor, RequestAuditContext $context): ExpedientReopeningRequest
    {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): ExpedientReopeningRequest {
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);

            if (! in_array($target->status, [ExpedientStatus::Archived, ExpedientStatus::Closed], true)) {
                throw ValidationException::withMessages(['expedient' => 'Solo los expedientes archivados o cerrados pueden solicitar reapertura.']);
            }

            if (! $actor->isCurrentManagerOfOffice($target->responsible_office_id)) {
                throw ValidationException::withMessages(['expedient' => 'La reapertura debe solicitarla una jefatura de la oficina responsable.']);
            }

            if ($target->reopeningRequests()->where('status', ReopeningRequestStatus::Pending->value)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['expedient' => 'Ya existe una solicitud de reapertura pendiente.']);
            }

            $request = ExpedientReopeningRequest::query()->create([
                'expedient_id' => $target->id,
                'requested_by' => $actor->id,
                'justification' => $data->reason,
                'status' => ReopeningRequestStatus::Pending,
            ]);

            $this->activityLogger->record(
                event: 'document_management.expedient_reopening.requested',
                actor: $actor,
                subject: $request,
                context: $context,
                newValues: $this->reopeningSnapshot($request),
            );

            return $request;
        });
    }

    public function approveReopening(
        Expedient $expedient,
        ExpedientReopeningRequest $request,
        ReopeningDecisionData $data,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientReopeningRequest {
        return DB::transaction(function () use ($expedient, $request, $data, $actor, $context): ExpedientReopeningRequest {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $targetRequest = ExpedientReopeningRequest::query()
                ->where('expedient_id', $targetExpedient->id)
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->assertCanApproveReopening($actor);

            if ($targetRequest->status !== ReopeningRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'La solicitud de reapertura ya fue resuelta.']);
            }

            $oldRequest = $this->reopeningSnapshot($targetRequest);
            $oldExpedient = $this->snapshot($targetExpedient);
            $targetRequest->update([
                'status' => ReopeningRequestStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $data->decisionNote,
            ]);
            $targetExpedient->update(['status' => ExpedientStatus::InProcess]);

            $this->activityLogger->record(
                event: 'document_management.expedient_reopening.approved',
                actor: $actor,
                subject: $targetRequest,
                context: $context,
                oldValues: $oldRequest,
                newValues: $this->reopeningSnapshot($targetRequest),
            );
            $this->activityLogger->record(
                event: 'document_management.expedient.reopened',
                actor: $actor,
                subject: $targetExpedient,
                context: $context,
                oldValues: $oldExpedient,
                newValues: $this->snapshot($targetExpedient),
            );

            return $targetRequest;
        });
    }

    public function rejectReopening(
        Expedient $expedient,
        ExpedientReopeningRequest $request,
        ReopeningDecisionData $data,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientReopeningRequest {
        return DB::transaction(function () use ($expedient, $request, $data, $actor, $context): ExpedientReopeningRequest {
            $targetRequest = ExpedientReopeningRequest::query()
                ->where('expedient_id', $expedient->id)
                ->lockForUpdate()
                ->findOrFail($request->id);
            $this->assertCanApproveReopening($actor);

            if ($targetRequest->status !== ReopeningRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'La solicitud de reapertura ya fue resuelta.']);
            }

            $oldValues = $this->reopeningSnapshot($targetRequest);
            $targetRequest->update([
                'status' => ReopeningRequestStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $data->decisionNote,
            ]);

            $this->activityLogger->record(
                event: 'document_management.expedient_reopening.rejected',
                actor: $actor,
                subject: $targetRequest,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->reopeningSnapshot($targetRequest),
            );

            return $targetRequest;
        });
    }

    private function assertCanManageLifecycle(Expedient $expedient, User $actor, OfficeCapabilityCode $capability): void
    {
        $holderOfficeIds = $expedient->currentHolderOfficeIds();

        if (! $actor->currentOfficeMemberships()->whereIn('office_id', $holderOfficeIds)->exists()) {
            throw ValidationException::withMessages([
                'expedient' => 'Solo la oficina que actualmente tiene el expediente puede gestionar su ciclo de vida.',
            ]);
        }

        if ($actor->currentOfficeMemberships()
            ->whereIn('office_id', $holderOfficeIds)
            ->whereHas('office.capabilities', fn ($query) => $query->where('capability', $capability->value))
            ->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'expedient' => 'El usuario no cuenta con permisos para esta acción de ciclo de vida.',
        ]);
    }

    private function assertCanApproveReopening(User $actor): void
    {
        if (! $actor->hasCurrentOfficeCapability(OfficeCapabilityCode::ApproveReopenings, requiresManager: true)) {
            throw ValidationException::withMessages([
                'reopening' => 'La aprobación o rechazo de reaperturas corresponde a una jefatura de OMAF.',
            ]);
        }
    }

    private function assertAllRecipientsFinished(Expedient $expedient): void
    {
        $hasPendingRecipients = ExpedientMovementRecipient::query()
            ->whereHas('movement', fn ($movement) => $movement->where('expedient_id', $expedient->id))
            ->whereIn('status', [
                MovementRecipientStatus::Pending->value,
                MovementRecipientStatus::Received->value,
                MovementRecipientStatus::InProcess->value,
            ])
            ->exists();

        if ($hasPendingRecipients) {
            throw ValidationException::withMessages([
                'expedient' => 'No se puede archivar mientras existan destinatarios pendientes o en trámite.',
            ]);
        }
    }

    /** @param array<string, mixed> $oldValues */
    private function recordAction(
        string $action,
        Expedient $expedient,
        User $actor,
        RequestAuditContext $context,
        array $oldValues,
        string $reason,
    ): void {
        $this->activityLogger->record(
            event: "document_management.expedient.{$action}",
            actor: $actor,
            subject: $expedient,
            context: $context,
            oldValues: $oldValues,
            newValues: $this->snapshot($expedient) + ['reason' => $reason],
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(Expedient $expedient): array
    {
        return [
            'id' => $expedient->id,
            'route_code' => $expedient->route_code,
            'status' => $expedient->status->value,
            'archived_at' => $expedient->archived_at?->toIso8601String(),
            'closed_at' => $expedient->closed_at?->toIso8601String(),
            'voided_at' => $expedient->voided_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function reopeningSnapshot(ExpedientReopeningRequest $request): array
    {
        return [
            'id' => $request->id,
            'expedient_id' => $request->expedient_id,
            'requested_by' => $request->requested_by,
            'justification' => $request->justification,
            'status' => $request->status->value,
            'decided_by' => $request->decided_by,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_note' => $request->decision_note,
        ];
    }
}
