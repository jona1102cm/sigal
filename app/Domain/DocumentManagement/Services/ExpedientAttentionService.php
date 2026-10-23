<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\DocumentManagement\Enums\AssignmentRequestStatus;
use App\Domain\DocumentManagement\Enums\InternalAssignmentRole;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\Legislatures\Services\LegislativeAuthorizationService;
use App\Models\Expedient;
use App\Models\ExpedientAssignmentRequest;
use App\Models\ExpedientInternalAssignment;
use App\Models\ExpedientMovementRecipient;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Separa el acuse individual de lectura del control operativo exclusivo.
 * Abrir nunca asigna; tomar, transferir o aceptar una solicitud sí modifica
 * el intervalo histórico del responsable dentro de una transacción.
 */
class ExpedientAttentionService
{
    public function __construct(
        private readonly OfficeDocumentAccessService $officeAccess,
        private readonly LegislativeAuthorizationService $legislativeAuthorization,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** Limita una consulta de destinatarios a llegadas reales visibles para el usuario. */
    public function applyEligibleRecipientAccess(Builder $query, User $user): void
    {
        [$officeIds, $legislatureMemberIds, $legislativeBodyOfficeIds] = $this->accessContext($user);

        $query->where(function (Builder $access) use ($officeIds, $legislatureMemberIds, $legislativeBodyOfficeIds, $user): void {
            if ($officeIds->isNotEmpty()) {
                $access->whereIn('recipient_office_id', $officeIds);
            } else {
                $access->whereRaw('1 = 0');
            }

            if ($legislatureMemberIds->isNotEmpty()) {
                $access->orWhereIn('attention_legislature_member_id', $legislatureMemberIds);
            }

            if ($legislativeBodyOfficeIds->isNotEmpty()) {
                $access->orWhere(function (Builder $bodyAccess) use ($legislativeBodyOfficeIds): void {
                    $bodyAccess->whereNull('attention_legislature_member_id')
                        ->whereIn('recipient_office_id', $legislativeBodyOfficeIds);
                });
            }

            $access->orWhereHas('currentInternalAssignments', fn (Builder $assignment) => $assignment
                ->where('user_id', $user->id));
        });
    }

    /** Aplica la bandeja de llegadas que este usuario todavía no abrió. */
    public function applyUnreadScope(Builder $query, User $user): void
    {
        $query->whereHas('latestMovement.recipients', function (Builder $recipient) use ($user): void {
            $this->applyEligibleRecipientAccess($recipient, $user);
            $recipient->whereDoesntHave('reads', fn (Builder $read) => $read->where('user_id', $user->id));
        });
    }

    /** Evita duplicar una llegada no leída en Pendientes o Finalizados. */
    public function excludeUnread(Builder $query, User $user): void
    {
        $query->whereDoesntHave('latestMovement.recipients', function (Builder $recipient) use ($user): void {
            $this->applyEligibleRecipientAccess($recipient, $user);
            $recipient->whereDoesntHave('reads', fn (Builder $read) => $read->where('user_id', $user->id));
        });
    }

    /** Registra una sola vez la primera apertura de cada destino vigente alcanzado por el usuario. */
    public function markCurrentArrivalRead(Expedient $expedient, User $actor, RequestAuditContext $context): int
    {
        $readCount = 0;
        $now = now();

        foreach ($this->currentEligibleRecipients($expedient, $actor) as $recipient) {
            $inserted = DB::table('expedient_recipient_reads')->insertOrIgnore([
                'expedient_movement_recipient_id' => $recipient->id,
                'user_id' => $actor->id,
                'first_opened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                continue;
            }

            $readCount++;
            $this->activityLogger->record(
                event: 'document_management.expedient_recipient.opened',
                actor: $actor,
                subject: $recipient,
                context: $context,
                newValues: ['first_opened_at' => $now->toIso8601String()],
            );
        }

        return $readCount;
    }

    /** @return array<string, mixed>|null */
    public function attentionPayload(Expedient $expedient, User $actor, RequestAuditContext $context): ?array
    {
        $recipient = $this->preferredRecipient($this->currentEligibleRecipients($expedient, $actor));
        if ($recipient === null) {
            return null;
        }

        $recipient->loadMissing([
            'movement',
            'recipientOffice',
            'currentInternalAssignments.user',
            'pendingAssignmentRequests.requestedBy',
        ]);
        $responsible = $recipient->currentInternalAssignments
            ->first(fn (ExpedientInternalAssignment $assignment) => $assignment->assignment_role === InternalAssignmentRole::Responsible);
        $isTerminal = $this->recipientIsTerminal($recipient) || ! $recipient->movement->requires_response;
        $canPotentiallyOperate = ! $isTerminal && $this->canPotentiallyOperate($actor, $expedient, $recipient, $context);
        $isResponsible = $responsible !== null && (int) $responsible->user_id === (int) $actor->id;
        $myPendingRequest = $recipient->pendingAssignmentRequests
            ->firstWhere('requested_by', $actor->id);

        return [
            'recipient_id' => $recipient->id,
            'office' => [
                'id' => $recipient->recipientOffice->id,
                'code' => $recipient->recipientOffice->code,
                'name' => $recipient->recipientOffice->name,
            ],
            'recipient_kind' => $recipient->recipient_kind->value,
            'requires_response' => (bool) $recipient->movement->requires_response,
            'recipient_status' => $recipient->status->value,
            'recipient_status_label' => $recipient->status->label(),
            'state' => $isTerminal ? 'informational_or_completed' : ($responsible === null ? 'unassigned' : 'assigned'),
            'responsible' => $responsible === null ? null : [
                'id' => $responsible->user_id,
                'name' => $responsible->user->name,
                'assigned_at' => $responsible->effective_from->toIso8601String(),
            ],
            'is_responsible' => $isResponsible,
            'can_claim' => $canPotentiallyOperate && $responsible === null,
            'can_request_assignment' => $canPotentiallyOperate && $responsible !== null && ! $isResponsible && $myPendingRequest === null,
            'has_pending_request' => $myPendingRequest !== null,
            'can_transfer' => ! $isTerminal && $isResponsible,
            'eligible_transfer_users' => $isResponsible ? $this->eligibleTransferUsers($recipient, $actor)->values()->all() : [],
            // Los motivos de terceros solo se exponen a quien debe decidirlos.
            'pending_requests' => $isResponsible
                ? $recipient->pendingAssignmentRequests->map(fn (ExpedientAssignmentRequest $request) => [
                    'id' => $request->id,
                    'requested_by' => [
                        'id' => $request->requestedBy->id,
                        'name' => $request->requestedBy->name,
                    ],
                    'justification' => $request->justification,
                    'requested_at' => $request->requested_at->toIso8601String(),
                    'can_decide' => true,
                ])->values()->all()
                : [],
        ];
    }

    public function claim(Expedient $expedient, ExpedientMovementRecipient $recipient, User $actor, RequestAuditContext $context): void
    {
        DB::transaction(function () use ($expedient, $recipient, $actor, $context): void {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $target = $this->lockedActionableRecipient($targetExpedient, $recipient);
            $this->assertCanPotentiallyOperate($actor, $targetExpedient, $target, $context);

            if ($this->currentResponsible($target, true) !== null) {
                throw ValidationException::withMessages(['recipient' => 'Otro funcionario ya tomó el control de esta atención.']);
            }

            $this->assignResponsible($target, $actor, $actor);
            $this->activityLogger->record(
                event: 'document_management.expedient_attention.claimed',
                actor: $actor,
                subject: $target,
                context: $context,
                newValues: ['responsible_user_id' => $actor->id],
            );
        });
    }

    public function requestAssignment(Expedient $expedient, ExpedientMovementRecipient $recipient, ?string $justification, User $actor, RequestAuditContext $context): ExpedientAssignmentRequest
    {
        return DB::transaction(function () use ($expedient, $recipient, $justification, $actor, $context): ExpedientAssignmentRequest {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $target = $this->lockedActionableRecipient($targetExpedient, $recipient);
            $this->assertCanPotentiallyOperate($actor, $targetExpedient, $target, $context);
            $responsible = $this->currentResponsible($target, true);

            if ($responsible === null) {
                throw ValidationException::withMessages(['recipient' => 'La atención está libre; puede tomar el control directamente.']);
            }
            if ((int) $responsible->user_id === (int) $actor->id) {
                throw ValidationException::withMessages(['recipient' => 'Usted ya tiene el control de esta atención.']);
            }
            if ($target->pendingAssignmentRequests()->where('requested_by', $actor->id)->exists()) {
                throw ValidationException::withMessages(['recipient' => 'Ya tiene una solicitud de asignación pendiente.']);
            }

            $request = $target->assignmentRequests()->create([
                'requested_by' => $actor->id,
                'justification' => $justification,
                'status' => AssignmentRequestStatus::Pending,
                'requested_at' => now(),
            ]);
            $this->activityLogger->record(
                event: 'document_management.expedient_assignment.requested',
                actor: $actor,
                subject: $request,
                context: $context,
                newValues: ['responsible_user_id' => $responsible->user_id, 'justification' => $justification],
            );

            return $request->load('requestedBy');
        });
    }

    public function decideRequest(Expedient $expedient, ExpedientMovementRecipient $recipient, ExpedientAssignmentRequest $request, bool $accept, ?string $decisionNote, User $actor, RequestAuditContext $context): void
    {
        DB::transaction(function () use ($expedient, $recipient, $request, $accept, $decisionNote, $actor, $context): void {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $target = $this->lockedActionableRecipient($targetExpedient, $recipient);
            $targetRequest = ExpedientAssignmentRequest::query()
                ->where('expedient_movement_recipient_id', $target->id)
                ->lockForUpdate()
                ->findOrFail($request->id);
            $responsible = $this->currentResponsible($target, true);

            if ($targetRequest->status !== AssignmentRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Esta solicitud ya fue resuelta.']);
            }
            if ($responsible === null || (int) $responsible->user_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['request' => 'Solo el responsable operativo actual puede resolver la solicitud.']);
            }

            if ($accept) {
                $requester = User::query()->where('status', 'active')->findOrFail($targetRequest->requested_by);
                $this->assertTransferCandidate($target, $requester);
                $this->assignResponsible($target, $requester, $actor);
            }

            $now = now();
            $targetRequest->update([
                'status' => $accept ? AssignmentRequestStatus::Accepted : AssignmentRequestStatus::Rejected,
                'decided_by' => $actor->id,
                'decision_note' => $decisionNote,
                'decided_at' => $now,
            ]);
            if ($accept) {
                $this->cancelOtherRequests($target, $targetRequest->id, $actor, $now);
            }

            $this->activityLogger->record(
                event: $accept
                    ? 'document_management.expedient_assignment.accepted'
                    : 'document_management.expedient_assignment.rejected',
                actor: $actor,
                subject: $targetRequest,
                context: $context,
                newValues: [
                    'decision_note' => $decisionNote,
                    'responsible_user_id' => $accept ? $targetRequest->requested_by : $responsible->user_id,
                ],
            );
        });
    }

    public function transfer(Expedient $expedient, ExpedientMovementRecipient $recipient, User $newResponsible, ?string $note, User $actor, RequestAuditContext $context): void
    {
        DB::transaction(function () use ($expedient, $recipient, $newResponsible, $note, $actor, $context): void {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $target = $this->lockedActionableRecipient($targetExpedient, $recipient);
            $responsible = $this->currentResponsible($target, true);

            if ($responsible === null || (int) $responsible->user_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['recipient' => 'Solo el responsable operativo actual puede transferir el control.']);
            }
            if ((int) $newResponsible->id === (int) $actor->id) {
                throw ValidationException::withMessages(['new_responsible_user_id' => 'Seleccione a otra persona.']);
            }

            $this->assertTransferCandidate($target, $newResponsible);
            $this->assignResponsible($target, $newResponsible, $actor);
            $this->cancelOtherRequests($target, null, $actor, now());
            $this->activityLogger->record(
                event: 'document_management.expedient_attention.transferred',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: ['responsible_user_id' => $responsible->user_id],
                newValues: ['responsible_user_id' => $newResponsible->id, 'note' => $note],
            );
        });
    }

    /** @return Collection<int, ExpedientMovementRecipient> */
    private function currentEligibleRecipients(Expedient $expedient, User $user): Collection
    {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return collect();
        }

        return ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovementId)
            ->with(['movement', 'recipientOffice'])
            ->tap(fn (Builder $query) => $this->applyEligibleRecipientAccess($query, $user))
            ->get();
    }

    private function preferredRecipient(Collection $recipients): ?ExpedientMovementRecipient
    {
        return $recipients->sortBy(fn (ExpedientMovementRecipient $recipient) => $recipient->recipient_kind === MovementRecipientKind::Primary ? 0 : 1)->first();
    }

    private function lockedActionableRecipient(Expedient $expedient, ExpedientMovementRecipient $recipient): ExpedientMovementRecipient
    {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        $target = ExpedientMovementRecipient::query()->with(['movement', 'recipientOffice'])->lockForUpdate()->findOrFail($recipient->id);

        if ((int) $target->expedient_movement_id !== (int) $latestMovementId
            || $target->recipient_kind !== MovementRecipientKind::Primary
            || ! $target->movement->requires_response
            || $this->recipientIsTerminal($target)) {
            throw ValidationException::withMessages(['recipient' => 'Solo puede controlarse una recepción principal vigente que requiera respuesta.']);
        }

        return $target;
    }

    private function recipientIsTerminal(ExpedientMovementRecipient $recipient): bool
    {
        return in_array($recipient->status, [
            MovementRecipientStatus::Responded,
            MovementRecipientStatus::Returned,
            MovementRecipientStatus::Rejected,
            MovementRecipientStatus::Completed,
        ], true);
    }

    private function canPotentiallyOperate(User $user, Expedient $expedient, ExpedientMovementRecipient $recipient, RequestAuditContext $context): bool
    {
        if (! $user->hasPermission(PermissionCode::ExpedientsProcess)) {
            return false;
        }

        $canActLegislatively = $recipient->attention_legislature_member_id !== null
            ? $this->legislativeAuthorization->canActAsIndividual(
                $user,
                (int) $recipient->attention_legislature_member_id,
                $context->actingLegislatureMemberId,
            )
            : $this->legislativeAuthorization->canActForOffice(
                $user,
                (int) $recipient->recipient_office_id,
                $context->actingBodyMembershipId,
            );

        return $this->officeAccess->canRequestOperationalControl($user, $expedient, (int) $recipient->recipient_office_id)
            || $canActLegislatively;
    }

    private function assertCanPotentiallyOperate(User $user, Expedient $expedient, ExpedientMovementRecipient $recipient, RequestAuditContext $context): void
    {
        if (! $this->canPotentiallyOperate($user, $expedient, $recipient, $context)) {
            throw ValidationException::withMessages(['recipient' => 'Su acceso permite consultar, pero no tomar el control de esta atención.']);
        }
    }

    private function currentResponsible(ExpedientMovementRecipient $recipient, bool $lock = false): ?ExpedientInternalAssignment
    {
        $query = $recipient->currentInternalAssignments()
            ->where('assignment_role', InternalAssignmentRole::Responsible->value)
            ->with('user');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function assignResponsible(ExpedientMovementRecipient $recipient, User $newResponsible, User $assignedBy): void
    {
        $now = now();
        $recipient->currentInternalAssignments()
            ->where(function (Builder $assignment) use ($newResponsible): void {
                $assignment->where('assignment_role', InternalAssignmentRole::Responsible->value)
                    ->orWhere('user_id', $newResponsible->id);
            })
            ->lockForUpdate()
            ->get()
            ->each->update(['effective_to' => $now]);

        $recipient->internalAssignments()->create([
            'user_id' => $newResponsible->id,
            'assignment_role' => InternalAssignmentRole::Responsible,
            'assigned_by' => $assignedBy->id,
            'effective_from' => $now,
        ]);
    }

    private function assertTransferCandidate(ExpedientMovementRecipient $recipient, User $candidate): void
    {
        $isOfficeMember = $candidate->currentOfficeMemberships()
            ->where('office_id', $recipient->recipient_office_id)
            ->exists();
        $isLegislativeCandidate = $recipient->attention_legislature_member_id !== null
            ? $candidate->currentLegislatureMemberships()
                ->whereKey($recipient->attention_legislature_member_id)
                ->exists()
            : $this->legislativeAuthorization
                ->isEffectiveAuthorityForOffice($candidate, (int) $recipient->recipient_office_id);

        if (! $candidate->isActive() || (! $isOfficeMember && ! $isLegislativeCandidate)) {
            throw ValidationException::withMessages([
                'new_responsible_user_id' => 'La nueva persona responsable debe estar habilitada para actuar por la oficina o atención legislativa destinataria.',
            ]);
        }
    }

    /** @return Collection<int, array{id: int, name: string, position_title: string}> */
    private function eligibleTransferUsers(ExpedientMovementRecipient $recipient, User $actor): Collection
    {
        $officeUsers = $recipient->recipientOffice->currentMemberships()
            ->with('user')
            ->whereHas('user', fn (Builder $user) => $user->where('status', 'active'))
            ->get()
            ->map(fn ($membership) => [
                'id' => (int) $membership->user_id,
                'name' => $membership->user->name,
                'position_title' => $membership->position_title ?: 'Funcionario',
            ]);
        $legislativeUsers = LegislativeBodyMembership::query()
            ->where('effective_from', '<=', now())
            ->where(fn (Builder $membership) => $membership->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('body', fn (Builder $body) => $body
                ->where('office_id', $recipient->recipient_office_id)
                ->where('effective_from', '<=', now())
                ->where(fn (Builder $active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now())))
            ->with('legislatureMember.user')
            ->get()
            ->filter(function ($membership) use ($recipient): bool {
                $user = $membership->legislatureMember->user;
                if (! $user->isActive()) {
                    return false;
                }

                if ($recipient->attention_legislature_member_id !== null) {
                    return (int) $membership->legislature_member_id === (int) $recipient->attention_legislature_member_id;
                }

                return $this->legislativeAuthorization->isEffectiveAuthorityForOffice(
                    $user,
                    (int) $recipient->recipient_office_id,
                );
            })
            ->map(fn ($membership) => [
                'id' => (int) $membership->legislatureMember->user_id,
                'name' => $membership->legislatureMember->user->name,
                'position_title' => $membership->role->label(),
            ]);

        return $officeUsers->merge($legislativeUsers)
            ->reject(fn (array $user) => $user['id'] === (int) $actor->id)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    private function cancelOtherRequests(ExpedientMovementRecipient $recipient, ?int $exceptId, User $actor, mixed $decidedAt): void
    {
        $recipient->pendingAssignmentRequests()
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->update([
                'status' => AssignmentRequestStatus::Cancelled->value,
                'decided_by' => $actor->id,
                'decision_note' => 'Cancelada automáticamente al cambiar el responsable operativo.',
                'decided_at' => $decidedAt,
                'updated_at' => $decidedAt,
            ]);
    }

    /** @return array{Collection<int, int>, Collection<int, int>, Collection<int, int>} */
    private function accessContext(User $user): array
    {
        $membershipOfficeIds = $user->currentOfficeMemberships()->pluck('office_id');
        $legislativeAdministrativeOfficeIds = LegislativeBody::query()
            ->whereIn('office_id', $membershipOfficeIds)
            ->where('effective_from', '<=', now())
            ->where(fn (Builder $body) => $body->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('legislature', fn (Builder $legislature) => $legislature->active())
            ->pluck('office_id');
        $officeIds = $this->officeAccess->automaticOfficeIds($user)
            ->merge($legislativeAdministrativeOfficeIds)
            ->unique()
            ->values();
        $legislatureMemberIds = $user->currentLegislatureMemberships()->pluck('id');
        $legislativeBodyOfficeIds = LegislativeBodyMembership::query()
            ->whereIn('legislature_member_id', $legislatureMemberIds)
            ->where('legislative_body_memberships.effective_from', '<=', now())
            ->where(fn (Builder $membership) => $membership
                ->whereNull('legislative_body_memberships.effective_to')
                ->orWhere('legislative_body_memberships.effective_to', '>', now()))
            ->whereHas('body.legislature', fn (Builder $legislature) => $legislature->active())
            ->join('legislative_bodies', 'legislative_bodies.id', '=', 'legislative_body_memberships.legislative_body_id')
            ->pluck('legislative_bodies.office_id')
            ->unique()
            ->values();

        return [$officeIds, $legislatureMemberIds, $legislativeBodyOfficeIds];
    }
}
