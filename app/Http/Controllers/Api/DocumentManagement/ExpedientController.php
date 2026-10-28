<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Services\ObserverOfficeScopeService;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\DocumentManagement\Services\ExpedientAttentionService;
use App\Domain\DocumentManagement\Services\ExpedientRegistrationService;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\StoreExpedientRequest;
use App\Http\Resources\DocumentManagement\ExpedientResource;
use App\Models\Expedient;
use App\Models\ExpedientInternalAssignment;
use App\Models\ExpedientRecipientRead;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/** Lista, muestra y registra la cabecera excepcional de expedientes. */
class ExpedientController extends Controller
{
    private const RELATIONS = [
        'legislature',
        'confidentialityLevel',
        'originOffice',
        'responsibleOffice',
        'createdBy',
    ];

    public function __construct(
        private readonly ExpedientRegistrationService $expedientRegistrationService,
        private readonly ExpedientAttentionService $expedientAttentionService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expedient::class);
        $search = trim((string) $request->query('search', ''));
        $requestedScope = (string) $request->query('scope', 'new');
        $scope = in_array($requestedScope, ['new', 'pending', 'finalized'], true) ? $requestedScope : 'new';

        $newCountQuery = Expedient::query()->visibleTo($request->user());
        $this->expedientAttentionService->applyUnreadScope($newCountQuery, $request->user());
        $newCount = $newCountQuery->count();

        $expedients = Expedient::query()
            ->visibleTo($request->user())
            ->tap(fn (Builder $query) => $this->applyInboxScope($query, $request->user(), $scope))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = "%{$search}%";

                $query->where(function (Builder $matches) use ($like): void {
                    $matches->where('route_code', 'ilike', $like)
                        ->orWhere('subject', 'ilike', $like)
                        ->orWhere('sender_name', 'ilike', $like)
                        ->orWhereHas('documents', function (Builder $documents) use ($like): void {
                            $documents->where(function (Builder $document) use ($like): void {
                                $document->where('office_reference', 'ilike', $like)
                                    ->orWhere('origin_number', 'ilike', $like)
                                    ->orWhereHas('numberSeries', fn (Builder $series) => $series
                                        ->where('formatted_number', 'ilike', $like));
                            });
                        });
                });
            })
            ->with(self::RELATIONS)
            ->latest('id')
            ->paginate();

        $this->activityLogger->record(
            event: 'document_management.expedient.listed',
            actor: $request->user(),
            subject: null,
            context: RequestAuditContext::fromRequest($request),
        );

        return ExpedientResource::collection($expedients)->additional([
            'inbox' => ['new_count' => $newCount],
        ]);
    }

    /** @param Builder<Expedient> $query */
    private function applyInboxScope(Builder $query, User $user, string $scope): void
    {
        if ($scope === 'new') {
            $this->expedientAttentionService->applyUnreadScope($query, $user);

            return;
        }

        $officeIds = app(OfficeDocumentAccessService::class)->automaticOfficeIds($user);
        if ($user->hasActiveRole(RoleCode::Observer)) {
            $officeIds = $officeIds
                ->merge(app(ObserverOfficeScopeService::class)->effectiveOfficeIds($user))
                ->unique()
                ->values();
        }

        $canReviewEveryOffice = $user->isSuperAdministrator();
        $membershipOfficeIds = $user->currentOfficeMemberships()->pluck('office_id');
        $legislativeAdministrativeOfficeIds = LegislativeBody::query()
            ->whereIn('office_id', $membershipOfficeIds)
            ->where('effective_from', '<=', now())
            ->where(fn ($body) => $body->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('legislature', fn ($legislature) => $legislature->active())
            ->pluck('office_id');
        $officeIds = $officeIds->merge($legislativeAdministrativeOfficeIds)->unique()->values();
        $legislatureMemberIds = $user->currentLegislatureMemberships()->pluck('id');
        $legislativeBodyOfficeIds = LegislativeBodyMembership::query()
            ->whereIn('legislature_member_id', $legislatureMemberIds)
            ->where('legislative_body_memberships.effective_from', '<=', now())
            ->where(fn ($membership) => $membership
                ->whereNull('legislative_body_memberships.effective_to')
                ->orWhere('legislative_body_memberships.effective_to', '>', now()))
            ->whereHas('body.legislature', fn ($legislature) => $legislature->active())
            ->join('legislative_bodies', 'legislative_bodies.id', '=', 'legislative_body_memberships.legislative_body_id')
            ->pluck('legislative_bodies.office_id')
            ->unique()
            ->values();
        $hasInternalAssignments = $membershipOfficeIds->isNotEmpty() && ExpedientInternalAssignment::query()
            ->where('user_id', $user->id)
            ->where('effective_from', '<=', now())
            ->where(fn ($assignment) => $assignment->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('recipient', fn ($recipient) => $recipient->whereIn('recipient_office_id', $membershipOfficeIds))
            ->exists();
        $hasHistoricalReads = $scope === 'finalized'
            && ExpedientRecipientRead::query()->where('user_id', $user->id)->exists();

        if (! $canReviewEveryOffice
            && $officeIds->isEmpty()
            && ! $hasInternalAssignments
            && ! $hasHistoricalReads
            && $legislatureMemberIds->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope === 'finalized') {
            $query->where(function (Builder $finalized) use ($officeIds, $canReviewEveryOffice, $user, $legislatureMemberIds, $legislativeBodyOfficeIds): void {
                $finalized->whereIn('status', [
                    ExpedientStatus::FullyResponded->value,
                    ExpedientStatus::Archived->value,
                    ExpedientStatus::Closed->value,
                    ExpedientStatus::Voided->value,
                ])->orWhere(function (Builder $participation) use ($officeIds, $canReviewEveryOffice, $user, $legislatureMemberIds, $legislativeBodyOfficeIds): void {
                    $this->whereCurrentRecipientMatches(
                        $participation,
                        [
                            MovementRecipientStatus::Responded->value,
                            MovementRecipientStatus::Returned->value,
                            MovementRecipientStatus::Rejected->value,
                            MovementRecipientStatus::Completed->value,
                        ],
                        $officeIds,
                        $canReviewEveryOffice,
                        false,
                        $user->id,
                        $legislatureMemberIds,
                        $legislativeBodyOfficeIds,
                    );
                });
            });
            $this->expedientAttentionService->excludeUnread($query, $user);

            return;
        }

        $query->where(function (Builder $pending) use ($officeIds, $canReviewEveryOffice, $user, $legislatureMemberIds, $legislativeBodyOfficeIds): void {
            $pending->where(function (Builder $participation) use ($officeIds, $canReviewEveryOffice, $user, $legislatureMemberIds, $legislativeBodyOfficeIds): void {
                $this->whereCurrentRecipientMatches(
                    $participation,
                    [
                        MovementRecipientStatus::Pending->value,
                        MovementRecipientStatus::Received->value,
                        MovementRecipientStatus::InProcess->value,
                    ],
                    $officeIds,
                    $canReviewEveryOffice,
                    true,
                    $user->id,
                    $legislatureMemberIds,
                    $legislativeBodyOfficeIds,
                );
            })->orWhere(function (Builder $unrouted) use ($officeIds, $canReviewEveryOffice): void {
                $unrouted->doesntHave('movements');

                if (! $canReviewEveryOffice) {
                    $unrouted->whereIn('responsible_office_id', $officeIds);
                }
            });
        });
        $this->expedientAttentionService->excludeUnread($query, $user);
    }

    /**
     * Applies an EXISTS clause for the recipient of the most recent derivation only.
     *
     * @param  Builder<Expedient>  $query
     * @param  list<string>  $statuses
     * @param  Collection<int, int>  $officeIds
     * @param  Collection<int, int>  $legislatureMemberIds
     * @param  Collection<int, int>  $legislativeBodyOfficeIds
     */
    private function whereCurrentRecipientMatches(
        Builder $query,
        array $statuses,
        Collection $officeIds,
        bool $canReviewEveryOffice,
        bool $requiresResponse,
        int $userId,
        Collection $legislatureMemberIds,
        Collection $legislativeBodyOfficeIds,
    ): void {
        $query->whereExists(function ($recipient) use ($statuses, $officeIds, $canReviewEveryOffice, $requiresResponse, $userId, $legislatureMemberIds, $legislativeBodyOfficeIds): void {
            $recipient->selectRaw('1')
                ->from('expedient_movements as current_movement')
                ->join('expedient_movement_recipients as current_recipient', 'current_recipient.expedient_movement_id', '=', 'current_movement.id')
                ->whereColumn('current_movement.expedient_id', 'expedients.id')
                ->whereRaw('current_movement.id = (SELECT latest_movement.id FROM expedient_movements AS latest_movement WHERE latest_movement.expedient_id = expedients.id ORDER BY latest_movement.sent_at DESC, latest_movement.id DESC LIMIT 1)')
                ->where('current_recipient.recipient_kind', MovementRecipientKind::Primary->value)
                ->whereIn('current_recipient.status', $statuses);

            if ($requiresResponse) {
                $recipient->where('current_movement.requires_response', true);
            }

            if (! $canReviewEveryOffice) {
                $recipient->where(function ($access) use ($officeIds, $userId, $legislatureMemberIds, $legislativeBodyOfficeIds): void {
                    if ($officeIds->isNotEmpty()) {
                        $access->whereIn('current_recipient.recipient_office_id', $officeIds);
                    } else {
                        $access->whereRaw('1 = 0');
                    }

                    if ($legislatureMemberIds->isNotEmpty()) {
                        $access->orWhereIn('current_recipient.attention_legislature_member_id', $legislatureMemberIds);
                    }

                    if ($legislativeBodyOfficeIds->isNotEmpty()) {
                        $access->orWhere(function ($bodyAccess) use ($legislativeBodyOfficeIds): void {
                            $bodyAccess->whereNull('current_recipient.attention_legislature_member_id')
                                ->whereIn('current_recipient.recipient_office_id', $legislativeBodyOfficeIds);
                        });
                    }

                    $access->orWhereExists(function ($assignment) use ($userId): void {
                        $assignment->selectRaw('1')
                            ->from('expedient_internal_assignments as internal_assignment')
                            ->whereColumn('internal_assignment.expedient_movement_recipient_id', 'current_recipient.id')
                            ->where('internal_assignment.user_id', $userId)
                            ->where('internal_assignment.effective_from', '<=', now())
                            ->where(function ($active): void {
                                $active->whereNull('internal_assignment.effective_to')
                                    ->orWhere('internal_assignment.effective_to', '>', now());
                            });
                    });
                });
            }
        });
    }

    public function store(StoreExpedientRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $expedientData = CreateExpedientData::fromValidated($validated);
        $expedient = $this->expedientRegistrationService->register(
            $expedientData,
            CreateExpedientMovementData::fromOptionalDerivation($validated, $expedientData->responsibleOfficeId),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new ExpedientResource($expedient))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Expedient $expedient): ExpedientResource
    {
        $this->authorize('view', $expedient);

        $expedient->load(self::RELATIONS);

        $this->activityLogger->record(
            event: 'document_management.expedient.viewed',
            actor: $request->user(),
            subject: $expedient,
            context: RequestAuditContext::fromRequest($request),
        );

        return new ExpedientResource($expedient);
    }
}
