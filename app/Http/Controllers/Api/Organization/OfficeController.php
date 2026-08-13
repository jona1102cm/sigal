<?php

namespace App\Http\Controllers\Api\Organization;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Organization\DTOs\AssignOfficeMembershipData;
use App\Domain\Organization\DTOs\CreateOfficeData;
use App\Domain\Organization\DTOs\UpdateOfficeData;
use App\Domain\Organization\Services\OfficeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\AssignOfficeMembershipRequest;
use App\Http\Requests\Organization\StoreOfficeRequest;
use App\Http\Requests\Organization\UpdateOfficeRequest;
use App\Http\Resources\Organization\OfficeDirectoryResource;
use App\Http\Resources\Organization\OfficeMembershipResource;
use App\Http\Resources\Organization\OfficeResource;
use App\Models\Office;
use App\Models\OfficeMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Adapta consultas/mutaciones del organigrama y membresías al dominio. */
class OfficeController extends Controller
{
    public function __construct(
        private readonly OfficeService $officeService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Office::class);

        $offices = Office::query()
            ->with(['parent', 'currentMemberships.user'])
            ->orderBy('code')
            ->paginate();

        $this->activityLogger->record(
            event: 'organization.office.listed',
            actor: $request->user(),
            subject: null,
            context: RequestAuditContext::fromRequest($request),
        );

        return OfficeResource::collection($offices);
    }

    public function directory(): AnonymousResourceCollection
    {
        $this->authorize('viewDirectory', Office::class);

        return OfficeDirectoryResource::collection(
            Office::query()->active()->supportingStaffing()->orderBy('code')->paginate(),
        );
    }

    public function store(StoreOfficeRequest $request): JsonResponse
    {
        $office = $this->officeService->create(
            CreateOfficeData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new OfficeResource($office->load(['parent', 'currentMemberships.user'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Office $office): OfficeResource
    {
        $this->authorize('view', $office);

        $office->load(['parent', 'currentMemberships.user']);

        $this->activityLogger->record(
            event: 'organization.office.viewed',
            actor: $request->user(),
            subject: $office,
            context: RequestAuditContext::fromRequest($request),
        );

        return new OfficeResource($office);
    }

    public function update(UpdateOfficeRequest $request, Office $office): OfficeResource
    {
        $office = $this->officeService->update(
            $office,
            UpdateOfficeData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new OfficeResource($office->load(['parent', 'currentMemberships.user']));
    }

    public function activate(Request $request, Office $office): OfficeResource
    {
        $this->authorize('update', $office);

        $office = $this->officeService->activate(
            $office,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new OfficeResource($office->load(['parent', 'currentMemberships.user']));
    }

    public function inactivate(Request $request, Office $office): OfficeResource
    {
        $this->authorize('update', $office);

        $office = $this->officeService->inactivate(
            $office,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new OfficeResource($office->load(['parent', 'currentMemberships.user']));
    }

    public function assignMembership(AssignOfficeMembershipRequest $request, Office $office): JsonResponse
    {
        $membership = $this->officeService->assignMembership(
            $office,
            AssignOfficeMembershipData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new OfficeMembershipResource($membership))
            ->response()
            ->setStatusCode(201);
    }

    public function memberships(Request $request, Office $office): AnonymousResourceCollection
    {
        $this->authorize('view', $office);

        $memberships = $office->memberships()
            ->with(['office', 'user'])
            ->latest('effective_from')
            ->paginate();

        return OfficeMembershipResource::collection($memberships);
    }

    public function closeMembership(Request $request, Office $office, OfficeMembership $membership): OfficeMembershipResource
    {
        $this->authorize('manageMemberships', $office);

        $membership = $this->officeService->closeMembership(
            $office,
            $membership,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new OfficeMembershipResource($membership->load(['office', 'user']));
    }
}
