<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\Enums\OfficeDocumentAccessMode;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Domain\Legislatures\DTOs\CreateLegislativeDelegationData;
use App\Domain\Legislatures\Services\LegislativeAuthorizationService;
use App\Domain\Legislatures\Services\LegislativeCompositionService;
use App\Domain\Organization\DTOs\CreateOfficeManagerDelegationData;
use App\Domain\Organization\Services\OfficeManagerDelegationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\EndOfficeManagerDelegationRequest;
use App\Http\Requests\DocumentManagement\StoreOfficeLegislativeDelegationRequest;
use App\Http\Requests\DocumentManagement\StoreOfficeManagerDelegationRequest;
use App\Http\Requests\DocumentManagement\UpdateOfficeDocumentAccessSettingRequest;
use App\Models\LegislativeDelegation;
use App\Models\Office;
use App\Models\OfficeManagerDelegation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Expone la preferencia permanente de reparto documental de cada oficina. */
class OfficeDocumentAccessController extends Controller
{
    public function __construct(
        private readonly OfficeDocumentAccessService $accessService,
        private readonly OfficeManagerDelegationService $managerDelegations,
        private readonly LegislativeAuthorizationService $legislativeAuthorization,
        private readonly LegislativeCompositionService $legislativeComposition,
    ) {}

    public function show(Request $request, Office $office): JsonResponse
    {
        $this->authorize('viewDocumentAccess', $office);

        return response()->json(['data' => $this->payload($office, $request->user())]);
    }

    public function update(UpdateOfficeDocumentAccessSettingRequest $request, Office $office): JsonResponse
    {
        $this->accessService->updateSetting(
            $office,
            OfficeDocumentAccessMode::from($request->validated('mode')),
            $request->validated('authorized_user_ids'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($office->fresh(), $request->user())]);
    }

    public function storeManagerDelegation(StoreOfficeManagerDelegationRequest $request, Office $office): JsonResponse
    {
        $this->managerDelegations->create(
            $office,
            CreateOfficeManagerDelegationData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($office->fresh(), $request->user())], 201);
    }

    public function endManagerDelegation(
        EndOfficeManagerDelegationRequest $request,
        Office $office,
        OfficeManagerDelegation $delegation,
    ): JsonResponse {
        $this->managerDelegations->end(
            $office,
            $delegation,
            CarbonImmutable::parse($request->validated('effective_to')),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($office->fresh(), $request->user())]);
    }

    public function storeLegislativeDelegation(
        StoreOfficeLegislativeDelegationRequest $request,
        Office $office,
    ): JsonResponse {
        $body = $this->legislativeAuthorization->currentBodyForOffice($office->id);
        $principal = $this->legislativeAuthorization->principalAuthorityMembershipForOffice($office->id);
        abort_if($body === null || $principal === null, 422, 'La oficina no tiene una autoridad legislativa vigente.');

        $this->legislativeComposition->createDelegation(
            $body,
            new CreateLegislativeDelegationData(
                principalMembershipId: $principal->id,
                delegateMembershipId: (int) $request->validated('delegate_membership_id'),
                effectiveFrom: CarbonImmutable::parse($request->validated('effective_from')),
                effectiveTo: CarbonImmutable::parse($request->validated('effective_to')),
                reason: $request->validated('reason'),
            ),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($office->fresh(), $request->user())], 201);
    }

    /** @return array<string, mixed> */
    private function payload(Office $office, User $actor): array
    {
        $office->load([
            'documentAccessSetting.currentAuthorizations.user',
            'currentManagerAssignment.user',
            'currentManagerDelegation.delegateUser',
            'managerDelegations' => fn ($query) => $query->latest('effective_from'),
            'managerDelegations.delegateUser',
            'managerDelegations.principalAssignment.user',
        ]);
        $memberships = $office->currentMemberships()
            ->with('user')
            ->whereHas('user', fn ($user) => $user->where('status', 'active'))
            ->orderBy('position_title')
            ->get();
        $mode = $this->accessService->modeFor($office);
        $body = $this->legislativeAuthorization->currentBodyForOffice($office->id);
        $body?->load([
            'currentMemberships.legislatureMember.user',
            'delegations' => fn ($query) => $query->latest('effective_from'),
            'delegations.principalMembership.legislatureMember.user',
            'delegations.delegateMembership.legislatureMember.user',
        ]);
        $principalAuthority = $body === null
            ? null
            : $this->legislativeAuthorization->principalAuthorityMembershipForOffice($office->id);
        $activeLegislativeDelegation = $principalAuthority === null ? null : $body->delegations
            ->first(fn (LegislativeDelegation $delegation) => (int) $delegation->principal_membership_id === (int) $principalAuthority->id
                && $delegation->effective_from->lessThanOrEqualTo(now())
                && $delegation->effective_to->isAfter(now()));
        $principalManager = $office->currentManagerAssignment;
        $activeManagerDelegation = $office->currentManagerDelegation;

        return [
            'office' => ['id' => $office->id, 'code' => $office->code, 'name' => $office->name],
            'mode' => $mode->value,
            'mode_label' => $mode->label(),
            'requires_manager' => $office->requires_manager,
            'office_kind' => $body === null ? 'administrative' : 'legislative_body',
            'can_update_setting' => $actor->can('configureDocumentAccess', $office),
            'can_manage_manager_delegations' => $actor->can('manageManagerDelegations', $office),
            'can_manage_legislative_delegations' => $actor->can('manageLegislativeDelegations', $office),
            'authorized_user_ids' => $office->documentAccessSetting?->currentAuthorizations
                ->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all() ?? [],
            'members' => $memberships->map(fn ($membership) => [
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'position_title' => $membership->position_title,
                'membership_role' => $membership->membership_role->value,
                'membership_role_label' => $membership->membership_role->label(),
                'is_manager' => $membership->user->isCurrentManagerOfOffice((int) $office->id),
            ])->values(),
            'leadership' => $body === null ? [
                'principal' => $principalManager === null ? null : [
                    'user_id' => $principalManager->user_id,
                    'name' => $principalManager->user->name,
                    'effective_from' => $principalManager->effective_from->toIso8601String(),
                    'effective_to' => $principalManager->effective_to?->toIso8601String(),
                ],
                'effective' => $activeManagerDelegation === null
                    ? ($principalManager === null ? null : [
                        'user_id' => $principalManager->user_id,
                        'name' => $principalManager->user->name,
                        'kind' => 'principal',
                    ])
                    : [
                        'user_id' => $activeManagerDelegation->delegate_user_id,
                        'name' => $activeManagerDelegation->delegateUser->name,
                        'kind' => 'delegate',
                    ],
                'active_delegation_id' => $activeManagerDelegation?->id,
                'delegate_candidates' => $memberships
                    ->filter(fn ($membership) => (int) $membership->user_id !== (int) $principalManager?->user_id)
                    ->map(fn ($membership) => [
                        'user_id' => $membership->user_id,
                        'name' => $membership->user->name,
                        'position_title' => $membership->position_title,
                    ])->values(),
                'delegations' => $office->managerDelegations->map(fn (OfficeManagerDelegation $delegation) => [
                    'id' => $delegation->id,
                    'principal_name' => $delegation->principalAssignment->user->name,
                    'delegate_name' => $delegation->delegateUser->name,
                    'effective_from' => $delegation->effective_from->toIso8601String(),
                    'effective_to' => $delegation->effective_to->toIso8601String(),
                    'reason' => $delegation->reason,
                    'cancelled_at' => $delegation->cancelled_at?->toIso8601String(),
                ])->values(),
            ] : [
                'body_id' => $body->id,
                'body_name' => $body->name,
                'principal' => $principalAuthority === null ? null : [
                    'membership_id' => $principalAuthority->id,
                    'user_id' => $principalAuthority->legislatureMember->user_id,
                    'name' => $principalAuthority->legislatureMember->user->name,
                    'role' => $principalAuthority->role->value,
                    'role_label' => $principalAuthority->role->label(),
                ],
                'effective' => $activeLegislativeDelegation === null
                    ? ($principalAuthority === null ? null : [
                        'membership_id' => $principalAuthority->id,
                        'name' => $principalAuthority->legislatureMember->user->name,
                        'kind' => 'principal',
                    ])
                    : [
                        'membership_id' => $activeLegislativeDelegation->delegate_membership_id,
                        'name' => $activeLegislativeDelegation->delegateMembership->legislatureMember->user->name,
                        'kind' => 'delegate',
                    ],
                'delegate_candidates' => $body->currentMemberships
                    ->filter(fn ($membership) => $principalAuthority !== null
                        && $membership->role->canReceiveDelegationFrom($principalAuthority->role))
                    ->map(fn ($membership) => [
                        'membership_id' => $membership->id,
                        'name' => $membership->legislatureMember->user->name,
                        'role_label' => $membership->role->label(),
                    ])->values(),
                'delegations' => $body->delegations->map(fn (LegislativeDelegation $delegation) => [
                    'id' => $delegation->id,
                    'principal_name' => $delegation->principalMembership->legislatureMember->user->name,
                    'delegate_name' => $delegation->delegateMembership->legislatureMember->user->name,
                    'effective_from' => $delegation->effective_from->toIso8601String(),
                    'effective_to' => $delegation->effective_to->toIso8601String(),
                    'reason' => $delegation->reason,
                ])->values(),
            ],
        ];
    }
}
