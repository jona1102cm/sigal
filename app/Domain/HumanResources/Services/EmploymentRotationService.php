<?php

namespace App\Domain\HumanResources\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\Enums\AssignmentRequestStatus;
use App\Domain\HumanResources\DTOs\CreateEmploymentRotationData;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Domain\Organization\Enums\OfficeStatus;
use App\Models\EmploymentContract;
use App\Models\EmploymentRotation;
use App\Models\EmploymentRotationAttachment;
use App\Models\ExpedientAssignmentRequest;
use App\Models\ExpedientInternalAssignment;
use App\Models\OfficeDocumentAccessAuthorization;
use App\Models\OfficeManagerAssignment;
use App\Models\OfficeMembership;
use App\Models\OfficePosition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Cambia la asignación operativa del funcionario sin modificar su fuente contractual. */
class EmploymentRotationService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly OfficeManagerAssignmentService $officeManagerAssignments,
    ) {}

    /** @return array<string, mixed> */
    public function preview(EmploymentContract $contract, CreateEmploymentRotationData $data): array
    {
        [$target, $source, $destination] = $this->resolveRotation($contract, $data, false);

        return $this->buildPreview($target, $source, $destination);
    }

    public function rotate(
        EmploymentContract $contract,
        CreateEmploymentRotationData $data,
        ?UploadedFile $attachment,
        User $actor,
        RequestAuditContext $context,
    ): EmploymentRotation {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($contract, $data, $attachment, $actor, $context, &$storedPath): EmploymentRotation {
                [$target, $source, $destination] = $this->resolveRotation($contract, $data, true);
                $preview = $this->buildPreview($target, $source, $destination);
                $operationalClosureAt = CarbonImmutable::now();

                $openRotation = EmploymentRotation::query()
                    ->where('employment_contract_id', $target->id)
                    ->whereNull('effective_to')
                    ->lockForUpdate()
                    ->first();
                if ($openRotation !== null) {
                    $oldRotationValues = ['effective_to' => null, 'ended_by' => null];
                    $openRotation->update([
                        'effective_to' => $data->effectiveFrom,
                        'ended_by' => $actor->id,
                    ]);
                    $this->activityLogger->record(
                        event: 'human_resources.employment_rotation.replaced',
                        actor: $actor,
                        subject: $openRotation,
                        context: $context,
                        oldValues: $oldRotationValues,
                        newValues: [
                            'effective_to' => $data->effectiveFrom->toIso8601String(),
                            'ended_by' => $actor->id,
                        ],
                    );
                }

                $rotation = EmploymentRotation::query()->create([
                    'employment_contract_id' => $target->id,
                    'source_office_membership_id' => $source->id,
                    'destination_office_position_id' => $destination->id,
                    'effective_from' => $data->effectiveFrom,
                    'reason' => $data->reason,
                    'memorandum_number' => $data->memorandumNumber,
                    'memorandum_date' => $data->memorandumDate,
                    'created_by' => $actor->id,
                ]);

                if ($source->membership_role === OfficeMembershipRole::Manager) {
                    $this->officeManagerAssignments->endForMembership(
                        membership: $source,
                        effectiveTo: $data->effectiveFrom,
                        closureNote: 'Cierre automático por rotación del titular a otra oficina.',
                        auditEvent: 'human_resources.office_manager_assignment.ended_on_rotation',
                        actor: $actor,
                        context: $context,
                    );
                }

                $source->update(['effective_to' => $data->effectiveFrom]);
                $releasedRecipientIds = $this->releaseDocumentAssignments(
                    $source,
                    $operationalClosureAt,
                    $actor,
                    $context,
                );
                $this->closeSourceOfficeAuthorizations($source, $operationalClosureAt, $actor, $context);
                $this->cancelPendingAssignmentRequests(
                    $source,
                    $releasedRecipientIds,
                    $operationalClosureAt,
                    $actor,
                    $context,
                );

                $destinationMembership = OfficeMembership::query()->create([
                    'employment_contract_id' => $target->id,
                    'employment_rotation_id' => $rotation->id,
                    'office_id' => $destination->office_id,
                    'office_position_id' => $destination->id,
                    'user_id' => $source->user_id,
                    'membership_role' => $destination->membership_role,
                    'position_title' => $destination->name,
                    'effective_from' => $data->effectiveFrom,
                    'assigned_by' => $actor->id,
                ]);

                if ($destinationMembership->membership_role === OfficeMembershipRole::Manager) {
                    $this->officeManagerAssignments->assignFromMembership(
                        $destinationMembership,
                        $actor,
                        $context,
                        $data->effectiveFrom,
                    );
                }

                if ($attachment instanceof UploadedFile) {
                    $storedPath = $this->storeAttachment($rotation, $attachment);
                    $rotationAttachment = EmploymentRotationAttachment::query()->create([
                        'employment_rotation_id' => $rotation->id,
                        'disk' => 'local',
                        'path' => $storedPath,
                        'original_name' => $attachment->getClientOriginalName(),
                        'mime_type' => $attachment->getMimeType() ?: 'application/octet-stream',
                        'size_bytes' => $attachment->getSize(),
                        'sha256' => hash_file('sha256', $attachment->getRealPath()),
                        'uploaded_by' => $actor->id,
                        'uploaded_at' => now(),
                    ]);

                    $this->activityLogger->record(
                        event: 'human_resources.employment_rotation.attachment_uploaded',
                        actor: $actor,
                        subject: $rotationAttachment,
                        context: $context,
                        newValues: [
                            'employment_rotation_id' => $rotation->id,
                            'original_name' => $rotationAttachment->original_name,
                            'mime_type' => $rotationAttachment->mime_type,
                            'size_bytes' => $rotationAttachment->size_bytes,
                            'sha256' => $rotationAttachment->sha256,
                        ],
                    );
                }

                $this->activityLogger->record(
                    event: 'human_resources.employment_rotation.created',
                    actor: $actor,
                    subject: $rotation,
                    context: $context,
                    newValues: [
                        'employment_contract_id' => $target->id,
                        'employee_id' => $target->employee_id,
                        'source_office_id' => $source->office_id,
                        'source_office_position_id' => $source->office_position_id,
                        'destination_office_id' => $destination->office_id,
                        'destination_office_position_id' => $destination->id,
                        'effective_from' => $data->effectiveFrom->toIso8601String(),
                        'reason' => $data->reason,
                        'memorandum_number' => $data->memorandumNumber,
                        'memorandum_date' => $data->memorandumDate?->toDateString(),
                        'released_expedient_recipients' => count($releasedRecipientIds),
                        'warnings' => $preview['warnings'],
                    ],
                );

                return $rotation->load($this->relations());
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /**
     * @return array{0: EmploymentContract, 1: OfficeMembership, 2: OfficePosition}
     */
    private function resolveRotation(
        EmploymentContract $contract,
        CreateEmploymentRotationData $data,
        bool $lock,
    ): array {
        $contractQuery = EmploymentContract::query()->with(['employee.user', 'officePosition.office']);
        $target = ($lock ? $contractQuery->lockForUpdate() : $contractQuery)->findOrFail($contract->id);

        if (! $target->isOpen() || $target->employee->user === null) {
            throw ValidationException::withMessages([
                'contract' => 'Solo se puede rotar a un funcionario con contrato y cuenta SIGAL vigentes.',
            ]);
        }
        if ($target->starts_on->isAfter(today()) || ($target->ends_on !== null && $target->ends_on->isBefore(today()))) {
            throw ValidationException::withMessages([
                'contract' => 'El contrato debe encontrarse vigente en la fecha actual para registrar una rotación.',
            ]);
        }
        if ($data->effectiveFrom->isFuture()) {
            throw ValidationException::withMessages([
                'effective_from' => 'La rotación puede regularizar una situación actual o pasada, pero no programarse a futuro.',
            ]);
        }
        if ($data->effectiveFrom->lessThan($target->starts_on->startOfDay())
            || ($target->ends_on !== null && $data->effectiveFrom->greaterThan($target->ends_on->endOfDay()))) {
            throw ValidationException::withMessages([
                'effective_from' => 'La fecha efectiva debe encontrarse dentro de la vigencia del contrato.',
            ]);
        }

        $sourceQuery = OfficeMembership::query()
            ->with(['office', 'officePosition', 'user'])
            ->where('employment_contract_id', $target->id)
            ->whereNull('effective_to');
        $source = ($lock ? $sourceQuery->lockForUpdate() : $sourceQuery)->first();
        if ($source === null) {
            throw ValidationException::withMessages([
                'contract' => 'El contrato no tiene una asignación operativa abierta. Recursos Humanos debe revisar su historial.',
            ]);
        }
        if ($data->effectiveFrom->lessThan($source->effective_from)) {
            throw ValidationException::withMessages([
                'effective_from' => 'La nueva disposición no puede ser anterior al inicio de la asignación operativa actual.',
            ]);
        }

        $destinationQuery = OfficePosition::query()->with('office');
        $destination = ($lock ? $destinationQuery->lockForUpdate() : $destinationQuery)
            ->findOrFail($data->destinationOfficePositionId);
        if ($destination->office->status !== OfficeStatus::Active || ! $destination->office->supports_staffing) {
            throw ValidationException::withMessages([
                'office_position_id' => 'La oficina de destino debe estar activa y admitir funcionarios.',
            ]);
        }
        if ((int) $destination->office_id === (int) $source->office_id) {
            throw ValidationException::withMessages([
                'office_position_id' => 'Seleccione una oficina distinta. Los cambios de cargo dentro de la misma oficina no son rotaciones.',
            ]);
        }
        if ($destination->membership_role === OfficeMembershipRole::Manager && ! $destination->office->requires_manager) {
            throw ValidationException::withMessages([
                'office_position_id' => 'La oficina de destino no admite un cargo responsable propio.',
            ]);
        }
        if (OfficeMembership::query()
            ->where('office_id', $destination->office_id)
            ->where('user_id', $source->user_id)
            ->whereNull('effective_to')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists()) {
            throw ValidationException::withMessages([
                'office_position_id' => 'El funcionario ya tiene una pertenencia vigente en la oficina de destino.',
            ]);
        }

        if ($destination->membership_role === OfficeMembershipRole::Manager) {
            $managerConflict = OfficeManagerAssignment::query()
                ->where('office_id', $destination->office_id)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $data->effectiveFrom))
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->exists();
            $legacyManagerConflict = OfficeMembership::query()
                ->where('office_id', $destination->office_id)
                ->where('membership_role', OfficeMembershipRole::Manager->value)
                ->whereNull('effective_to')
                ->whereKeyNot($source->id)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->exists();

            if ($managerConflict || $legacyManagerConflict) {
                throw ValidationException::withMessages([
                    'office_position_id' => 'La oficina de destino ya tiene una jefatura vigente o superpuesta. Seleccione otro cargo o regularice primero esa responsabilidad.',
                ]);
            }
        }

        return [$target, $source, $destination];
    }

    /** @return array<string, mixed> */
    private function buildPreview(
        EmploymentContract $contract,
        OfficeMembership $source,
        OfficePosition $destination,
    ): array {
        $activeMembersAfterRotation = OfficeMembership::query()
            ->where('office_id', $source->office_id)
            ->whereKeyNot($source->id)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->count();
        $pendingExpedients = ExpedientInternalAssignment::query()
            ->join('expedient_movement_recipients', 'expedient_movement_recipients.id', '=', 'expedient_internal_assignments.expedient_movement_recipient_id')
            ->join('expedient_movements', 'expedient_movements.id', '=', 'expedient_movement_recipients.expedient_movement_id')
            ->where('expedient_internal_assignments.user_id', $source->user_id)
            ->where('expedient_movement_recipients.recipient_office_id', $source->office_id)
            ->where('expedient_internal_assignments.effective_from', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('expedient_internal_assignments.effective_to')
                ->orWhere('expedient_internal_assignments.effective_to', '>', now()))
            ->distinct('expedient_movements.expedient_id')
            ->count('expedient_movements.expedient_id');
        $warnings = [];

        if ($activeMembersAfterRotation === 0) {
            $warnings[] = 'La oficina de origen quedará sin funcionarios vigentes.';
        }
        if ($source->membership_role === OfficeMembershipRole::Manager) {
            $warnings[] = 'La oficina de origen quedará sin responsable titular hasta una nueva contratación o rotación.';
        }
        if ($pendingExpedients > 0) {
            $warnings[] = "Se liberarán {$pendingExpedients} expediente(s) con asignación personal para que la oficina de origen los reasigne.";
        }

        return [
            'employee' => [
                'id' => $contract->employee->id,
                'full_name' => $contract->employee->fullName(),
                'identity_card' => $contract->employee->identity_card,
            ],
            'contract' => [
                'id' => $contract->id,
                'budget_office' => [
                    'id' => $contract->officePosition->office->id,
                    'code' => $contract->officePosition->office->code,
                    'name' => $contract->officePosition->office->name,
                ],
                'budget_position' => $contract->officePosition->name,
            ],
            'source' => [
                'office_id' => $source->office_id,
                'office_code' => $source->office->code,
                'office_name' => $source->office->name,
                'position_title' => $source->position_title,
            ],
            'destination' => [
                'office_id' => $destination->office_id,
                'office_code' => $destination->office->code,
                'office_name' => $destination->office->name,
                'office_position_id' => $destination->id,
                'position_name' => $destination->name,
                'membership_role' => $destination->membership_role->value,
                'membership_role_label' => $destination->membership_role->label(),
            ],
            'effects' => [
                'pending_expedients_released' => $pendingExpedients,
                'remaining_source_members' => $activeMembersAfterRotation,
                'contract_is_unchanged' => true,
                'account_remains_active' => true,
            ],
            'warnings' => $warnings,
        ];
    }

    /** @return list<int> */
    private function releaseDocumentAssignments(
        OfficeMembership $source,
        CarbonImmutable $closedAt,
        User $actor,
        RequestAuditContext $context,
    ): array {
        $assignments = ExpedientInternalAssignment::query()
            ->where('user_id', $source->user_id)
            ->where('effective_from', '<=', $closedAt)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $closedAt))
            ->whereHas('recipient', fn ($recipient) => $recipient->where('recipient_office_id', $source->office_id))
            ->lockForUpdate()
            ->get();
        $recipientIds = $assignments->pluck('expedient_movement_recipient_id')->unique()->values()->all();

        foreach ($assignments as $assignment) {
            $assignment->update(['effective_to' => $closedAt]);
            $this->activityLogger->record(
                event: 'document_management.internal_assignment.released_on_employee_rotation',
                actor: $actor,
                subject: $assignment,
                context: $context,
                oldValues: ['effective_to' => null],
                newValues: ['effective_to' => $closedAt->toIso8601String()],
            );
        }

        return $recipientIds;
    }

    private function closeSourceOfficeAuthorizations(
        OfficeMembership $source,
        CarbonImmutable $closedAt,
        User $actor,
        RequestAuditContext $context,
    ): void {
        $authorizations = OfficeDocumentAccessAuthorization::query()
            ->where('user_id', $source->user_id)
            ->where('effective_from', '<=', $closedAt)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $closedAt))
            ->whereHas('setting', fn ($setting) => $setting->where('office_id', $source->office_id))
            ->lockForUpdate()
            ->get();

        foreach ($authorizations as $authorization) {
            $authorization->update(['effective_to' => $closedAt]);
            $this->activityLogger->record(
                event: 'document_management.office_access_authorization.ended_on_employee_rotation',
                actor: $actor,
                subject: $authorization,
                context: $context,
                oldValues: ['effective_to' => null],
                newValues: ['effective_to' => $closedAt->toIso8601String()],
            );
        }
    }

    /** @param list<int> $releasedRecipientIds */
    private function cancelPendingAssignmentRequests(
        OfficeMembership $source,
        array $releasedRecipientIds,
        CarbonImmutable $decidedAt,
        User $actor,
        RequestAuditContext $context,
    ): void {
        $requests = ExpedientAssignmentRequest::query()
            ->where('status', AssignmentRequestStatus::Pending->value)
            ->where(function ($query) use ($source, $releasedRecipientIds): void {
                $query->where(function ($requestedBy) use ($source): void {
                    $requestedBy->where('requested_by', $source->user_id)
                        ->whereHas('recipient', fn ($recipient) => $recipient
                            ->where('recipient_office_id', $source->office_id));
                });

                if ($releasedRecipientIds !== []) {
                    $query->orWhereIn('expedient_movement_recipient_id', $releasedRecipientIds);
                }
            })
            ->lockForUpdate()
            ->get();

        foreach ($requests as $request) {
            $request->update([
                'status' => AssignmentRequestStatus::Cancelled->value,
                'decided_by' => $actor->id,
                'decision_note' => 'Cancelada automáticamente por rotación del funcionario fuera de la oficina.',
                'decided_at' => $decidedAt,
            ]);
            $this->activityLogger->record(
                event: 'document_management.assignment_request.cancelled_on_employee_rotation',
                actor: $actor,
                subject: $request,
                context: $context,
                oldValues: ['status' => AssignmentRequestStatus::Pending->value],
                newValues: [
                    'status' => AssignmentRequestStatus::Cancelled->value,
                    'decided_by' => $actor->id,
                    'decided_at' => $decidedAt->toIso8601String(),
                ],
            );
        }
    }

    private function storeAttachment(EmploymentRotation $rotation, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'bin';

        return Storage::disk('local')->putFileAs(
            "human-resources/rotations/{$rotation->id}",
            $file,
            Str::uuid().".{$extension}",
        );
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'sourceOfficeMembership.office',
            'destinationOfficePosition.office',
            'destinationOfficeMembership.office',
            'attachment',
            'createdBy',
        ];
    }
}
