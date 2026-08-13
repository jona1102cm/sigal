<?php

namespace App\Http\Controllers\Api\Legislatures;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Legislatures\DTOs\CreateLegislatureData;
use App\Domain\Legislatures\DTOs\ReplaceBoardMemberData;
use App\Domain\Legislatures\DTOs\UpdateLegislatureData;
use App\Domain\Legislatures\Services\LegislatureService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Legislatures\ReplaceBoardMemberRequest;
use App\Http\Requests\Legislatures\StoreLegislatureRequest;
use App\Http\Requests\Legislatures\UpdateLegislatureRequest;
use App\Http\Resources\Legislatures\LegislatureBoardAssignmentResource;
use App\Http\Resources\Legislatures\LegislatureResource;
use App\Models\Legislature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LegislatureController extends Controller
{
    public function __construct(
        private readonly LegislatureService $legislatureService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Legislature::class);

        $legislatures = Legislature::query()
            ->with(['currentBoardAssignments.user'])
            ->orderByDesc('start_year')
            ->paginate();

        $this->activityLogger->record(
            event: 'legislature.listed',
            actor: $request->user(),
            subject: null,
            context: RequestAuditContext::fromRequest($request),
        );

        return LegislatureResource::collection($legislatures);
    }

    public function store(StoreLegislatureRequest $request): JsonResponse
    {
        $legislature = $this->legislatureService->create(
            CreateLegislatureData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new LegislatureResource($legislature))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Legislature $legislature): LegislatureResource
    {
        $this->authorize('view', $legislature);

        $legislature->load(['currentBoardAssignments.user']);

        $this->activityLogger->record(
            event: 'legislature.viewed',
            actor: $request->user(),
            subject: $legislature,
            context: RequestAuditContext::fromRequest($request),
        );

        return new LegislatureResource($legislature);
    }

    public function update(UpdateLegislatureRequest $request, Legislature $legislature): LegislatureResource
    {
        $legislature = $this->legislatureService->update(
            $legislature,
            UpdateLegislatureData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new LegislatureResource($legislature);
    }

    public function activate(Request $request, Legislature $legislature): LegislatureResource
    {
        $this->authorize('update', $legislature);

        $legislature = $this->legislatureService->activate(
            $legislature,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new LegislatureResource($legislature);
    }

    public function inactivate(Request $request, Legislature $legislature): LegislatureResource
    {
        $this->authorize('update', $legislature);

        $legislature = $this->legislatureService->inactivate(
            $legislature,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new LegislatureResource($legislature);
    }

    public function replaceBoardMember(
        ReplaceBoardMemberRequest $request,
        Legislature $legislature,
    ): JsonResponse {
        $assignment = $this->legislatureService->replaceBoardMember(
            $legislature,
            ReplaceBoardMemberData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new LegislatureBoardAssignmentResource($assignment))
            ->response()
            ->setStatusCode(201);
    }
}
