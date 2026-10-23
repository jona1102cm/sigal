<?php

namespace App\Domain\Organization\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Organization\DTOs\CreateOfficeManagerDelegationData;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\LegislativeBody;
use App\Models\Office;
use App\Models\OfficeManagerAssignment;
use App\Models\OfficeManagerDelegation;
use App\Models\OfficeMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Administra suplencias temporales sin modificar el cargo ni el contrato titular. */
class OfficeManagerDelegationService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function create(
        Office $office,
        CreateOfficeManagerDelegationData $data,
        User $actor,
        RequestAuditContext $context,
    ): OfficeManagerDelegation {
        return DB::transaction(function () use ($office, $data, $actor, $context): OfficeManagerDelegation {
            $targetOffice = Office::query()->lockForUpdate()->findOrFail($office->id);
            if (! $targetOffice->requires_manager || ! $targetOffice->supports_staffing) {
                throw ValidationException::withMessages([
                    'office' => 'Esta unidad no utiliza una jefatura contractual delegable.',
                ]);
            }
            if (LegislativeBody::query()
                ->where('office_id', $targetOffice->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('legislature', fn ($query) => $query->active())
                ->exists()) {
                throw ValidationException::withMessages([
                    'office' => 'Las comisiones y bancadas utilizan delegaciones de su autoridad legislativa, no suplencias contractuales.',
                ]);
            }

            $principal = OfficeManagerAssignment::query()
                ->where('office_id', $targetOffice->id)
                ->where('effective_from', '<=', $data->effectiveFrom)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $data->effectiveTo))
                ->lockForUpdate()
                ->latest('effective_from')
                ->first();
            if ($principal === null) {
                throw ValidationException::withMessages([
                    'effective_from' => 'No existe un responsable contractual que cubra todo el período de delegación.',
                ]);
            }

            $delegateMembership = OfficeMembership::query()
                ->with('user')
                ->where('office_id', $targetOffice->id)
                ->where('user_id', $data->delegateUserId)
                ->where('membership_role', OfficeMembershipRole::Official->value)
                ->where('effective_from', '<=', $data->effectiveFrom)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $data->effectiveTo))
                ->lockForUpdate()
                ->first();
            if ($delegateMembership === null || ! $delegateMembership->user->isActive()) {
                throw ValidationException::withMessages([
                    'delegate_user_id' => 'Seleccione un funcionario activo cuyo contrato cubra todo el período de delegación.',
                ]);
            }

            if ((int) $principal->user_id === (int) $delegateMembership->user_id) {
                throw ValidationException::withMessages([
                    'delegate_user_id' => 'El responsable titular no puede delegarse a sí mismo.',
                ]);
            }

            if (OfficeManagerDelegation::query()
                ->where('office_id', $targetOffice->id)
                ->whereNull('cancelled_at')
                ->where('effective_from', '<', $data->effectiveTo)
                ->where('effective_to', '>', $data->effectiveFrom)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Ya existe una delegación de esta oficina que se superpone con el período indicado.',
                ]);
            }

            $delegation = OfficeManagerDelegation::query()->create([
                'office_id' => $targetOffice->id,
                'principal_assignment_id' => $principal->id,
                'delegate_membership_id' => $delegateMembership->id,
                'delegate_user_id' => $delegateMembership->user_id,
                'effective_from' => $data->effectiveFrom,
                'effective_to' => $data->effectiveTo,
                'reason' => $data->reason,
                'created_by' => $actor->id,
            ]);

            $this->activityLogger->record(
                event: 'organization.office_manager_delegation.created',
                actor: $actor,
                subject: $delegation,
                context: $context,
                newValues: $this->snapshot($delegation),
            );

            return $delegation->load($this->relations());
        });
    }

    public function end(
        Office $office,
        OfficeManagerDelegation $delegation,
        CarbonImmutable $effectiveTo,
        User $actor,
        RequestAuditContext $context,
    ): OfficeManagerDelegation {
        return DB::transaction(function () use ($office, $delegation, $effectiveTo, $actor, $context): OfficeManagerDelegation {
            $target = OfficeManagerDelegation::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->findOrFail($delegation->id);
            if ($target->cancelled_at !== null) {
                throw ValidationException::withMessages(['delegation' => 'La delegación ya fue cancelada.']);
            }
            if ($effectiveTo->isAfter($target->effective_to)) {
                throw ValidationException::withMessages([
                    'effective_to' => 'La finalización debe estar dentro del período originalmente delegado.',
                ]);
            }

            $oldValues = $this->snapshot($target);
            $target->update($effectiveTo->isAfter($target->effective_from)
                ? ['effective_to' => $effectiveTo, 'ended_by' => $actor->id]
                : ['cancelled_at' => now(), 'ended_by' => $actor->id]);
            $this->activityLogger->record(
                event: 'organization.office_manager_delegation.ended',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target->load($this->relations());
        });
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['office', 'principalAssignment.user', 'delegateMembership', 'delegateUser', 'createdBy', 'endedBy'];
    }

    /** @return array<string, mixed> */
    private function snapshot(OfficeManagerDelegation $delegation): array
    {
        return [
            'id' => $delegation->id,
            'office_id' => $delegation->office_id,
            'principal_assignment_id' => $delegation->principal_assignment_id,
            'delegate_membership_id' => $delegation->delegate_membership_id,
            'delegate_user_id' => $delegation->delegate_user_id,
            'effective_from' => $delegation->effective_from->toIso8601String(),
            'effective_to' => $delegation->effective_to->toIso8601String(),
            'reason' => $delegation->reason,
            'cancelled_at' => $delegation->cancelled_at?->toIso8601String(),
        ];
    }
}
