<?php

namespace App\Http\Controllers\Api\Authorization;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Services\ObserverOfficeScopeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\UpdateObserverOfficeScopeRequest;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configura el alcance por oficina de una cuenta con rol Observador. */
class ObserverOfficeScopeController extends Controller
{
    public function __construct(private readonly ObserverOfficeScopeService $scopeService) {}

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageRoles', $user);

        return response()->json(['data' => $this->payload($user)]);
    }

    public function update(UpdateObserverOfficeScopeRequest $request, User $user): JsonResponse
    {
        $this->scopeService->sync(
            $user,
            $request->validated('office_ids'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($user)]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $assignment = $user->currentRoleAssignments()
            ->whereHas('role', fn ($role) => $role->where('code', RoleCode::Observer->value))
            ->with('currentObserverOfficeScopes.office')
            ->first();
        $directIds = $assignment?->currentObserverOfficeScopes->pluck('office_id')->map(fn ($id) => (int) $id)->values() ?? collect();
        $effectiveIds = $this->scopeService->effectiveOfficeIds($user);
        $offices = Office::query()->whereIn('id', $effectiveIds)->orderBy('name')->get(['id', 'parent_id', 'code', 'name']);

        return [
            'has_observer_role' => $assignment !== null,
            'direct_office_ids' => $directIds->all(),
            'effective_office_ids' => $effectiveIds->all(),
            'effective_offices' => $offices,
        ];
    }
}
