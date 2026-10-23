<?php

namespace App\Domain\HumanResources\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\UserStatus;
use App\Domain\HumanResources\DTOs\DesignateOfficeManagerData;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Domain\Organization\Enums\OfficeStatus;
use App\Models\Office;
use App\Models\OfficeManagerAssignment;
use App\Models\OfficeManagerDelegation;
use App\Models\OfficeMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Administra la jefatura temporal sin alterar el cargo contractual del funcionario. */
class OfficeManagerAssignmentService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function designate(
        Office $office,
        DesignateOfficeManagerData $data,
        User $actor,
        RequestAuditContext $context,
    ): OfficeManagerAssignment {
        return DB::transaction(function () use ($office, $data, $actor, $context): OfficeManagerAssignment {
            $targetOffice = Office::query()->lockForUpdate()->findOrFail($office->id);
            $this->assertManageableOffice($targetOffice);

            $membership = OfficeMembership::query()
                ->with('user')
                ->where('office_id', $targetOffice->id)
                ->where('user_id', $data->userId)
                ->where('effective_from', '<=', $data->effectiveFrom)
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $data->effectiveFrom))
                ->lockForUpdate()
                ->first();

            if ($membership === null
                || $membership->membership_role !== OfficeMembershipRole::Manager
                || ($membership->user->status !== UserStatus::Active && $data->effectiveFrom->lessThanOrEqualTo(now()))) {
                throw ValidationException::withMessages([
                    'user_id' => 'El responsable debe tener un cargo contractual de jefatura vigente en esta oficina.',
                ]);
            }

            $overlaps = OfficeManagerAssignment::query()
                ->where('office_id', $targetOffice->id)
                ->where('effective_from', '<', $data->effectiveTo ?? CarbonImmutable::create(9999, 12, 31, 23, 59, 59))
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $data->effectiveFrom))
                ->lockForUpdate()
                ->orderBy('effective_from')
                ->get();

            $futureConflict = $overlaps->first(fn (OfficeManagerAssignment $assignment) => $assignment->effective_from->greaterThanOrEqualTo($data->effectiveFrom)
            );
            if ($futureConflict !== null) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Ya existe una designación programada que se superpone con este período.',
                ]);
            }

            foreach ($overlaps as $previous) {
                $oldValues = $this->snapshot($previous);
                $this->closeDelegationsForAssignment($previous, $data->effectiveFrom, $actor, $context);
                $previous->update([
                    'effective_to' => $data->effectiveFrom,
                    'closure_note' => 'Cierre automático por designación de un nuevo responsable.',
                    'ended_by' => $actor->id,
                ]);
                $this->activityLogger->record(
                    event: 'human_resources.office_manager_assignment.replaced',
                    actor: $actor,
                    subject: $previous,
                    context: $context,
                    oldValues: $oldValues,
                    newValues: $this->snapshot($previous),
                );
            }

            $assignment = OfficeManagerAssignment::query()->create([
                'office_id' => $targetOffice->id,
                'office_membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'effective_from' => $data->effectiveFrom,
                'effective_to' => $data->effectiveTo,
                'source' => 'contract_position',
                'designation_note' => $data->designationNote,
                'designated_by' => $actor->id,
            ]);

            $this->activityLogger->record(
                event: 'human_resources.office_manager_assignment.designated',
                actor: $actor,
                subject: $assignment,
                context: $context,
                newValues: $this->snapshot($assignment),
            );

            return $assignment->load($this->relations());
        });
    }

    /**
     * Proyecta automáticamente el cargo vigente a la responsabilidad de oficina.
     * La operación es idempotente para que importación, alta manual y reparaciones
     * puedan reutilizar exactamente la misma regla.
     */
    public function assignFromMembership(
        OfficeMembership $membership,
        User $actor,
        RequestAuditContext $context,
        ?CarbonImmutable $effectiveFrom = null,
    ): OfficeManagerAssignment {
        $target = OfficeMembership::query()
            ->with(['office', 'user'])
            ->lockForUpdate()
            ->findOrFail($membership->id);

        if ($target->membership_role !== OfficeMembershipRole::Manager) {
            throw ValidationException::withMessages([
                'membership_role' => 'Solo un cargo vigente de jefatura puede originar la responsabilidad de oficina.',
            ]);
        }

        $startsAt = $effectiveFrom ?? CarbonImmutable::parse($target->effective_from);
        $existing = OfficeManagerAssignment::query()
            ->where('office_membership_id', $target->id)
            ->where('effective_from', '<=', $startsAt)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $startsAt))
            ->first();

        if ($existing !== null) {
            return $existing->load($this->relations());
        }

        return $this->designate(
            $target->office,
            new DesignateOfficeManagerData(
                userId: (int) $target->user_id,
                effectiveFrom: $startsAt,
                effectiveTo: $target->effective_to === null ? null : CarbonImmutable::parse($target->effective_to),
                designationNote: 'Asignación automática por cargo vigente de jefatura.',
            ),
            $actor,
            $context,
        );
    }

    public function end(
        Office $office,
        OfficeManagerAssignment $assignment,
        CarbonImmutable $effectiveTo,
        string $closureNote,
        User $actor,
        RequestAuditContext $context,
    ): OfficeManagerAssignment {
        return DB::transaction(function () use ($office, $assignment, $effectiveTo, $closureNote, $actor, $context): OfficeManagerAssignment {
            Office::query()->lockForUpdate()->findOrFail($office->id);
            $target = OfficeManagerAssignment::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if ($effectiveTo->lessThanOrEqualTo($target->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_to' => 'La finalización debe ser posterior al inicio de la designación.',
                ]);
            }
            if ($target->effective_to !== null && $effectiveTo->greaterThan($target->effective_to)) {
                throw ValidationException::withMessages([
                    'effective_to' => 'La designación ya finaliza antes de la fecha indicada.',
                ]);
            }

            $oldValues = $this->snapshot($target);
            $this->closeDelegationsForAssignment($target, $effectiveTo, $actor, $context);
            $target->update([
                'effective_to' => $effectiveTo,
                'closure_note' => $closureNote,
                'ended_by' => $actor->id,
            ]);

            $this->activityLogger->record(
                event: 'human_resources.office_manager_assignment.ended',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target->load($this->relations());
        });
    }

    /** Cierra la responsabilidad cuando deja de existir la pertenencia laboral que la sustentaba. */
    public function endForMembership(
        OfficeMembership $membership,
        CarbonInterface $effectiveTo,
        string $closureNote,
        string $auditEvent,
        ?User $actor,
        RequestAuditContext $context,
    ): void {
        OfficeManagerDelegation::query()
            ->whereNull('cancelled_at')
            ->where(fn ($delegation) => $delegation
                ->where('delegate_membership_id', $membership->id)
                ->orWhereHas('principalAssignment', fn ($assignment) => $assignment
                    ->where('office_membership_id', $membership->id)))
            ->where('effective_to', '>', $effectiveTo)
            ->lockForUpdate()
            ->each(function (OfficeManagerDelegation $delegation) use ($effectiveTo, $actor, $context): void {
                $oldValues = [
                    'effective_to' => $delegation->effective_to->toIso8601String(),
                    'cancelled_at' => $delegation->cancelled_at?->toIso8601String(),
                ];
                $delegation->update($effectiveTo->isAfter($delegation->effective_from)
                    ? ['effective_to' => $effectiveTo, 'ended_by' => $actor?->id]
                    : ['cancelled_at' => $effectiveTo, 'ended_by' => $actor?->id]);
                $this->activityLogger->record(
                    event: 'organization.office_manager_delegation.ended_on_membership_close',
                    actor: $actor,
                    subject: $delegation,
                    context: $context,
                    oldValues: $oldValues,
                    newValues: [
                        'effective_to' => $delegation->effective_to->toIso8601String(),
                        'cancelled_at' => $delegation->cancelled_at?->toIso8601String(),
                    ],
                );
            });

        OfficeManagerAssignment::query()
            ->where('office_membership_id', $membership->id)
            ->where('effective_from', '<=', $effectiveTo)
            ->where(fn ($assignment) => $assignment
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $effectiveTo))
            ->lockForUpdate()
            ->each(function (OfficeManagerAssignment $assignment) use ($effectiveTo, $closureNote, $auditEvent, $actor, $context): void {
                $oldValues = $this->snapshot($assignment);
                $closedAt = $effectiveTo->lessThan($assignment->effective_from)
                    ? $assignment->effective_from
                    : $effectiveTo;
                $assignment->update([
                    'effective_to' => $closedAt,
                    'closure_note' => $closureNote,
                    'ended_by' => $actor?->id,
                ]);
                $this->activityLogger->record(
                    event: $auditEvent,
                    actor: $actor,
                    subject: $assignment,
                    context: $context,
                    oldValues: $oldValues,
                    newValues: $this->snapshot($assignment),
                );
            });
    }

    private function assertManageableOffice(Office $office): void
    {
        if ($office->status !== OfficeStatus::Active || ! $office->supports_staffing || ! $office->requires_manager) {
            throw ValidationException::withMessages([
                'office' => 'Solo las oficinas activas que requieren responsable admiten esta designación.',
            ]);
        }
    }

    /** Cierra o cancela suplencias que ya no caben en la vigencia del titular. */
    private function closeDelegationsForAssignment(
        OfficeManagerAssignment $assignment,
        CarbonInterface $effectiveTo,
        ?User $actor,
        RequestAuditContext $context,
    ): void {
        OfficeManagerDelegation::query()
            ->where('principal_assignment_id', $assignment->id)
            ->whereNull('cancelled_at')
            ->where('effective_to', '>', $effectiveTo)
            ->lockForUpdate()
            ->each(function (OfficeManagerDelegation $delegation) use ($effectiveTo, $actor, $context): void {
                $oldValues = [
                    'effective_to' => $delegation->effective_to->toIso8601String(),
                    'cancelled_at' => $delegation->cancelled_at?->toIso8601String(),
                ];
                $delegation->update($effectiveTo->isAfter($delegation->effective_from)
                    ? ['effective_to' => $effectiveTo, 'ended_by' => $actor?->id]
                    : ['cancelled_at' => $effectiveTo, 'ended_by' => $actor?->id]);
                $this->activityLogger->record(
                    event: 'organization.office_manager_delegation.ended_on_principal_change',
                    actor: $actor,
                    subject: $delegation,
                    context: $context,
                    oldValues: $oldValues,
                    newValues: [
                        'effective_to' => $delegation->effective_to->toIso8601String(),
                        'cancelled_at' => $delegation->cancelled_at?->toIso8601String(),
                    ],
                );
            });
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['office', 'officeMembership', 'user.employee', 'designatedBy', 'endedBy'];
    }

    /** @return array<string, mixed> */
    private function snapshot(OfficeManagerAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'office_id' => $assignment->office_id,
            'office_membership_id' => $assignment->office_membership_id,
            'user_id' => $assignment->user_id,
            'effective_from' => $assignment->effective_from->toIso8601String(),
            'effective_to' => $assignment->effective_to?->toIso8601String(),
            'source' => $assignment->source,
            'designation_note' => $assignment->designation_note,
            'closure_note' => $assignment->closure_note,
        ];
    }
}
