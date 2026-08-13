<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\DocumentManagement\Services\ExpedientRegistrationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\StoreExpedientRequest;
use App\Http\Resources\DocumentManagement\ExpedientResource;
use App\Models\Expedient;
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
        'expedientType',
        'confidentialityLevel',
        'originOffice',
        'responsibleOffice',
        'createdBy',
    ];

    public function __construct(
        private readonly ExpedientRegistrationService $expedientRegistrationService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expedient::class);
        $search = trim((string) $request->query('search', ''));
        $scope = $request->query('scope') === 'finalized' ? 'finalized' : 'pending';

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

        return ExpedientResource::collection($expedients);
    }

    /** @param Builder<Expedient> $query */
    private function applyInboxScope(Builder $query, User $user, string $scope): void
    {
        $officeIds = $user->currentOfficeMemberships()->pluck('office_id')->map(fn ($id) => (int) $id);
        $canReviewEveryOffice = $user->isSuperAdministrator()
            || $user->hasActiveRole(RoleCode::Observer);

        if (! $canReviewEveryOffice && $officeIds->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope === 'finalized') {
            $query->where(function (Builder $finalized) use ($officeIds, $canReviewEveryOffice): void {
                $finalized->whereIn('status', [
                    ExpedientStatus::FullyResponded->value,
                    ExpedientStatus::Archived->value,
                    ExpedientStatus::Closed->value,
                    ExpedientStatus::Voided->value,
                ])->orWhere(function (Builder $participation) use ($officeIds, $canReviewEveryOffice): void {
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
                    );
                });
            });

            return;
        }

        $query->where(function (Builder $pending) use ($officeIds, $canReviewEveryOffice): void {
            $pending->where(function (Builder $participation) use ($officeIds, $canReviewEveryOffice): void {
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
                );
            })->orWhere(function (Builder $unrouted) use ($officeIds, $canReviewEveryOffice): void {
                $unrouted->doesntHave('movements');

                if (! $canReviewEveryOffice) {
                    $unrouted->whereIn('responsible_office_id', $officeIds);
                }
            });
        });
    }

    /**
     * Applies an EXISTS clause for the recipient of the most recent derivation only.
     *
     * @param  Builder<Expedient>  $query
     * @param  list<string>  $statuses
     * @param  Collection<int, int>  $officeIds
     */
    private function whereCurrentRecipientMatches(
        Builder $query,
        array $statuses,
        Collection $officeIds,
        bool $canReviewEveryOffice,
        bool $requiresResponse,
    ): void {
        $query->whereExists(function ($recipient) use ($statuses, $officeIds, $canReviewEveryOffice, $requiresResponse): void {
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
                $recipient->whereIn('current_recipient.recipient_office_id', $officeIds);
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
