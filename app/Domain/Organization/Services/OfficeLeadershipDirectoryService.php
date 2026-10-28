<?php

namespace App\Domain\Organization\Services;

use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\LegislativeDelegation;
use App\Models\Office;
use Illuminate\Support\Collection;

/** Construye en lote el directorio vigente de autoridades por oficina. */
class OfficeLeadershipDirectoryService
{
    public function __construct(
        private readonly OfficeDocumentAccessService $documentAccess,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function current(): Collection
    {
        $offices = Office::query()
            ->active()
            ->supportingStaffing()
            ->where('requires_manager', true)
            ->with([
                'documentAccessSetting',
                'currentManagerAssignment.user',
                'currentManagerDelegation.delegateUser',
            ])
            ->orderBy('name')
            ->get();

        $bodies = LegislativeBody::query()
            ->whereIn('office_id', $offices->pluck('id'))
            ->where('effective_from', '<=', now())
            ->where(fn ($body) => $body->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('legislature', fn ($legislature) => $legislature->active())
            ->with('currentMemberships.legislatureMember.user')
            ->get()
            ->keyBy('office_id');

        $principals = $bodies
            ->map(fn (LegislativeBody $body) => $body->currentMemberships
                ->first(fn (LegislativeBodyMembership $membership) => $membership->role->isResponsible()))
            ->filter();

        $delegations = LegislativeDelegation::query()
            ->whereIn('principal_membership_id', $principals->pluck('id'))
            ->where('effective_from', '<=', now())
            ->where('effective_to', '>', now())
            ->with('delegateMembership.legislatureMember.user')
            ->get()
            ->keyBy('principal_membership_id');

        return $offices->map(function (Office $office) use ($bodies, $principals, $delegations): array {
            $body = $bodies->get($office->id);

            return $body instanceof LegislativeBody
                ? $this->legislativeRow($office, $body, $principals->get($office->id), $delegations)
                : $this->administrativeRow($office);
        });
    }

    /** @return array<string, mixed> */
    private function administrativeRow(Office $office): array
    {
        $principal = $office->currentManagerAssignment;
        $delegation = $office->currentManagerDelegation;

        return [
            'office' => $this->officePayload($office),
            'office_kind' => 'administrative',
            'office_kind_label' => 'Administrativa',
            'principal' => $principal === null ? null : [
                'user_id' => $principal->user_id,
                'name' => $principal->user->name,
                'role_label' => 'Responsable titular',
            ],
            'effective' => $delegation === null
                ? ($principal === null ? null : [
                    'user_id' => $principal->user_id,
                    'name' => $principal->user->name,
                    'kind' => 'principal',
                    'kind_label' => 'Titular',
                ])
                : [
                    'user_id' => $delegation->delegate_user_id,
                    'name' => $delegation->delegateUser->name,
                    'kind' => 'delegate',
                    'kind_label' => 'Delegación temporal',
                ],
            'state' => $principal === null ? 'missing' : ($delegation === null ? 'principal' : 'delegated'),
            'state_label' => $principal === null ? 'Sin titular vigente' : ($delegation === null ? 'Titular activo' : 'Delegación activa'),
            'access_mode' => $this->documentAccess->modeFor($office)->value,
            'access_mode_label' => $this->documentAccess->modeFor($office)->label(),
        ];
    }

    /**
     * @param  Collection<int, LegislativeDelegation>  $delegations
     * @return array<string, mixed>
     */
    private function legislativeRow(
        Office $office,
        LegislativeBody $body,
        ?LegislativeBodyMembership $principal,
        Collection $delegations,
    ): array {
        $delegation = $principal === null ? null : $delegations->get($principal->id);
        $principalUser = $principal?->legislatureMember?->user;
        $delegateUser = $delegation?->delegateMembership?->legislatureMember?->user;

        return [
            'office' => $this->officePayload($office),
            'office_kind' => 'legislative_body',
            'office_kind_label' => $body->body_type->label(),
            'principal' => $principalUser === null ? null : [
                'user_id' => $principalUser->id,
                'name' => $principalUser->name,
                'role_label' => $principal->role->label(),
            ],
            'effective' => $delegateUser === null
                ? ($principalUser === null ? null : [
                    'user_id' => $principalUser->id,
                    'name' => $principalUser->name,
                    'kind' => 'principal',
                    'kind_label' => 'Titular',
                ])
                : [
                    'user_id' => $delegateUser->id,
                    'name' => $delegateUser->name,
                    'kind' => 'delegate',
                    'kind_label' => 'Delegación temporal',
                ],
            'state' => $principalUser === null ? 'missing' : ($delegateUser === null ? 'principal' : 'delegated'),
            'state_label' => $principalUser === null ? 'Sin autoridad vigente' : ($delegateUser === null ? 'Titular activo' : 'Delegación activa'),
            'access_mode' => $this->documentAccess->modeFor($office)->value,
            'access_mode_label' => $this->documentAccess->modeFor($office)->label(),
        ];
    }

    /** @return array{id: int, code: string, name: string} */
    private function officePayload(Office $office): array
    {
        return [
            'id' => $office->id,
            'code' => $office->code,
            'name' => $office->name,
        ];
    }
}
