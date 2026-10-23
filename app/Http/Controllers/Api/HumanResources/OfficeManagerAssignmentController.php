<?php

namespace App\Http\Controllers\Api\HumanResources;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\HumanResources\DTOs\DesignateOfficeManagerData;
use App\Domain\HumanResources\Services\OfficeManagerAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\HumanResources\DesignateOfficeManagerRequest;
use App\Http\Requests\HumanResources\EndOfficeManagerAssignmentRequest;
use App\Http\Resources\HumanResources\OfficeManagerAssignmentResource;
use App\Models\Employee;
use App\Models\Office;
use App\Models\OfficeManagerAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Permite a RR. HH. designar una jefatura sin modificar el contrato del funcionario. */
class OfficeManagerAssignmentController extends Controller
{
    public function __construct(private readonly OfficeManagerAssignmentService $assignmentService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $offices = Office::query()
            ->active()
            ->supportingStaffing()
            ->where('requires_manager', true)
            ->with([
                'currentMemberships' => fn ($query) => $query
                    ->whereHas('user', fn ($user) => $user->where('status', 'active'))
                    ->with('user:id,employee_id,name,email,status')
                    ->orderBy('position_title'),
                'managerAssignments' => fn ($query) => $query
                    ->with(['user:id,employee_id,name,email,status', 'officeMembership', 'designatedBy:id,name', 'endedBy:id,name'])
                    ->latest('effective_from')
                    ->latest('id'),
                'currentManagerAssignment' => fn ($query) => $query
                    ->with(['user:id,employee_id,name,email,status', 'officeMembership', 'designatedBy:id,name', 'endedBy:id,name']),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $offices->map(function (Office $office) use ($request): array {
            $current = $office->currentManagerAssignment;

            return [
                'id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'current_manager' => $current === null
                    ? null
                    : (new OfficeManagerAssignmentResource($current))->toArray($request),
                'members' => $office->currentMemberships->map(fn ($membership) => [
                    'membership_id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'position_title' => $membership->position_title,
                    'is_current_manager' => $current !== null && (int) $current->user_id === (int) $membership->user_id,
                ])->values(),
                'history' => $office->managerAssignments
                    ->take(30)
                    ->map(fn ($assignment) => (new OfficeManagerAssignmentResource($assignment))->toArray($request))
                    ->values(),
            ];
        })->values()]);
    }

    public function store(DesignateOfficeManagerRequest $request, Office $office): JsonResponse
    {
        $assignment = $this->assignmentService->designate(
            $office,
            DesignateOfficeManagerData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new OfficeManagerAssignmentResource($assignment))
            ->response()
            ->setStatusCode(201);
    }

    public function end(
        EndOfficeManagerAssignmentRequest $request,
        Office $office,
        OfficeManagerAssignment $assignment,
    ): OfficeManagerAssignmentResource {
        return new OfficeManagerAssignmentResource($this->assignmentService->end(
            $office,
            $assignment,
            CarbonImmutable::parse($request->validated('effective_to')),
            $request->validated('closure_note'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }
}
