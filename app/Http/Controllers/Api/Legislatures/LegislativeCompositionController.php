<?php

namespace App\Http\Controllers\Api\Legislatures;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Legislatures\DTOs\AssignLegislativeBodyMembershipData;
use App\Domain\Legislatures\DTOs\AssignLegislatureMemberData;
use App\Domain\Legislatures\DTOs\CreateLegislativeBodyData;
use App\Domain\Legislatures\DTOs\CreateLegislativeDelegationData;
use App\Domain\Legislatures\Services\LegislativeCompositionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Legislatures\AssignLegislativeBodyMembershipRequest;
use App\Http\Requests\Legislatures\AssignLegislatureMemberRequest;
use App\Http\Requests\Legislatures\CloseLegislativeAssignmentRequest;
use App\Http\Requests\Legislatures\CreateLegislativeBodyRequest;
use App\Http\Requests\Legislatures\CreateLegislativeDelegationRequest;
use App\Http\Resources\Legislatures\LegislativeBodyMembershipResource;
use App\Http\Resources\Legislatures\LegislativeBodyResource;
use App\Http\Resources\Legislatures\LegislativeDelegationResource;
use App\Http\Resources\Legislatures\LegislatureMemberResource;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Administra la composición política e histórica de cada legislatura. */
class LegislativeCompositionController extends Controller
{
    public function __construct(private readonly LegislativeCompositionService $service) {}

    public function show(Request $request, Legislature $legislature): JsonResponse
    {
        $this->authorize('view', $legislature);

        $members = $legislature->currentMembers()
            ->with(['user', 'currentBodyMemberships.body.office'])
            ->orderBy('id')
            ->get();
        $bodies = $legislature->legislativeBodies()
            ->with([
                'office',
                'currentMemberships.legislatureMember.user',
                'delegations' => fn ($query) => $query->where('effective_to', '>', now()),
                'delegations.principalMembership.legislatureMember.user',
                'delegations.delegateMembership.legislatureMember.user',
            ])
            ->orderBy('body_type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'members' => LegislatureMemberResource::collection($members),
            'bodies' => LegislativeBodyResource::collection($bodies),
        ]);
    }

    public function assignMember(AssignLegislatureMemberRequest $request, Legislature $legislature): JsonResponse
    {
        $member = $this->service->assignMember(
            $legislature,
            AssignLegislatureMemberData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new LegislatureMemberResource($member))->response()->setStatusCode(201);
    }

    public function closeMember(
        CloseLegislativeAssignmentRequest $request,
        Legislature $legislature,
        LegislatureMember $legislatureMember,
    ): LegislatureMemberResource {
        return new LegislatureMemberResource($this->service->closeMember(
            $legislature,
            $legislatureMember,
            CarbonImmutable::parse($request->validated('effective_to')),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function createBody(CreateLegislativeBodyRequest $request, Legislature $legislature): JsonResponse
    {
        $body = $this->service->createBody(
            $legislature,
            CreateLegislativeBodyData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new LegislativeBodyResource($body))->response()->setStatusCode(201);
    }

    public function assignBodyMembership(
        AssignLegislativeBodyMembershipRequest $request,
        Legislature $legislature,
        LegislativeBody $legislativeBody,
    ): JsonResponse {
        abort_unless((int) $legislativeBody->legislature_id === (int) $legislature->id, 404);
        $membership = $this->service->assignBodyMembership(
            $legislativeBody,
            AssignLegislativeBodyMembershipData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new LegislativeBodyMembershipResource($membership))->response()->setStatusCode(201);
    }

    public function closeBodyMembership(
        CloseLegislativeAssignmentRequest $request,
        Legislature $legislature,
        LegislativeBody $legislativeBody,
        LegislativeBodyMembership $membership,
    ): LegislativeBodyMembershipResource {
        abort_unless((int) $legislativeBody->legislature_id === (int) $legislature->id, 404);

        return new LegislativeBodyMembershipResource($this->service->closeBodyMembership(
            $legislativeBody,
            $membership,
            CarbonImmutable::parse($request->validated('effective_to')),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function createDelegation(
        CreateLegislativeDelegationRequest $request,
        Legislature $legislature,
        LegislativeBody $legislativeBody,
    ): JsonResponse {
        abort_unless((int) $legislativeBody->legislature_id === (int) $legislature->id, 404);
        $delegation = $this->service->createDelegation(
            $legislativeBody,
            CreateLegislativeDelegationData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new LegislativeDelegationResource($delegation->load([
            'principalMembership.legislatureMember.user',
            'delegateMembership.legislatureMember.user',
        ])))->response()->setStatusCode(201);
    }
}
