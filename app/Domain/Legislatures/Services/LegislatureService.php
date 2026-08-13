<?php

namespace App\Domain\Legislatures\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Legislatures\DTOs\CreateLegislatureData;
use App\Domain\Legislatures\DTOs\ReplaceBoardMemberData;
use App\Domain\Legislatures\DTOs\UpdateLegislatureData;
use App\Domain\Legislatures\Enums\LegislatureStatus;
use App\Models\Legislature;
use App\Models\LegislatureBoardAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegislatureService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function create(
        CreateLegislatureData $data,
        User $actor,
        RequestAuditContext $context,
    ): Legislature {
        $this->assertConsecutiveYears($data->startYear, $data->endYear);
        $this->assertPeriodAvailable($data->startYear, $data->endYear);

        return DB::transaction(function () use ($data, $actor, $context): Legislature {
            $now = now();

            if ($data->status === LegislatureStatus::Active) {
                $this->inactivateCurrentWithinTransaction($actor, $context, $now);
            }

            $legislature = Legislature::query()->create([
                'start_year' => $data->startYear,
                'end_year' => $data->endYear,
                'status' => $data->status,
                'activated_at' => $data->status === LegislatureStatus::Active ? $now : null,
            ]);

            $this->activityLogger->record(
                event: 'legislature.created',
                actor: $actor,
                subject: $legislature,
                context: $context,
                newValues: $this->legislatureSnapshot($legislature),
            );

            return $legislature;
        });
    }

    public function update(
        Legislature $legislature,
        UpdateLegislatureData $data,
        User $actor,
        RequestAuditContext $context,
    ): Legislature {
        $this->assertConsecutiveYears($data->startYear, $data->endYear);
        $this->assertPeriodAvailable($data->startYear, $data->endYear, $legislature->id);

        return DB::transaction(function () use ($legislature, $data, $actor, $context): Legislature {
            $lockedLegislature = Legislature::query()->lockForUpdate()->findOrFail($legislature->id);
            $oldValues = $this->legislatureSnapshot($lockedLegislature);

            $lockedLegislature->update([
                'start_year' => $data->startYear,
                'end_year' => $data->endYear,
            ]);

            $this->activityLogger->record(
                event: 'legislature.updated',
                actor: $actor,
                subject: $lockedLegislature,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->legislatureSnapshot($lockedLegislature),
            );

            return $lockedLegislature;
        });
    }

    public function activate(Legislature $legislature, User $actor, RequestAuditContext $context): Legislature
    {
        return DB::transaction(function () use ($legislature, $actor, $context): Legislature {
            $target = Legislature::query()->lockForUpdate()->findOrFail($legislature->id);

            if ($target->status === LegislatureStatus::Active) {
                return $target;
            }

            $now = now();
            $this->inactivateCurrentWithinTransaction($actor, $context, $now);

            $oldValues = $this->legislatureSnapshot($target);
            $target->update([
                'status' => LegislatureStatus::Active,
                'activated_at' => $now,
                'inactivated_at' => null,
            ]);

            $this->activityLogger->record(
                event: 'legislature.activated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->legislatureSnapshot($target),
            );

            return $target;
        });
    }

    public function inactivate(Legislature $legislature, User $actor, RequestAuditContext $context): Legislature
    {
        return DB::transaction(function () use ($legislature, $actor, $context): Legislature {
            $target = Legislature::query()->lockForUpdate()->findOrFail($legislature->id);

            if ($target->status === LegislatureStatus::Inactive) {
                return $target;
            }

            $oldValues = $this->legislatureSnapshot($target);
            $target->update([
                'status' => LegislatureStatus::Inactive,
                'inactivated_at' => now(),
            ]);

            $this->activityLogger->record(
                event: 'legislature.inactivated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->legislatureSnapshot($target),
            );

            return $target;
        });
    }

    public function replaceBoardMember(
        Legislature $legislature,
        ReplaceBoardMemberData $data,
        User $actor,
        RequestAuditContext $context,
    ): LegislatureBoardAssignment {
        if (! $data->effectiveOn->isSameDay($data->effectiveAt->setTimezone(config('app.timezone')))) {
            throw ValidationException::withMessages([
                'effective_on' => 'La fecha efectiva debe coincidir con la fecha de la hora efectiva.',
            ]);
        }

        return DB::transaction(function () use ($legislature, $data, $actor, $context): LegislatureBoardAssignment {
            $currentAssignment = LegislatureBoardAssignment::query()
                ->where('legislature_id', $legislature->id)
                ->where('position', $data->position->value)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($currentAssignment !== null) {
                if ($data->effectiveAt->isBefore($currentAssignment->effective_at)) {
                    throw ValidationException::withMessages([
                        'effective_at' => 'El reemplazo no puede ser anterior al inicio del titular vigente.',
                    ]);
                }

                $oldValues = $this->boardAssignmentSnapshot($currentAssignment);
                $currentAssignment->update([
                    'ended_on' => $data->effectiveOn,
                    'ended_at' => $data->effectiveAt,
                ]);

                $this->activityLogger->record(
                    event: 'legislature.board_assignment.closed',
                    actor: $actor,
                    subject: $currentAssignment,
                    context: $context,
                    oldValues: $oldValues,
                    newValues: $this->boardAssignmentSnapshot($currentAssignment),
                );
            }

            $assignment = LegislatureBoardAssignment::query()->create([
                'legislature_id' => $legislature->id,
                'user_id' => $data->userId,
                'position' => $data->position,
                'effective_on' => $data->effectiveOn,
                'effective_at' => $data->effectiveAt,
            ]);

            $this->activityLogger->record(
                event: 'legislature.board_assignment.assigned',
                actor: $actor,
                subject: $assignment,
                context: $context,
                newValues: $this->boardAssignmentSnapshot($assignment),
            );

            return $assignment->load('user');
        });
    }

    private function inactivateCurrentWithinTransaction(
        User $actor,
        RequestAuditContext $context,
        \DateTimeInterface $inactivatedAt,
    ): void {
        $currentLegislature = Legislature::query()->active()->lockForUpdate()->first();

        if ($currentLegislature === null) {
            return;
        }

        $oldValues = $this->legislatureSnapshot($currentLegislature);
        $currentLegislature->update([
            'status' => LegislatureStatus::Inactive,
            'inactivated_at' => $inactivatedAt,
        ]);

        $this->activityLogger->record(
            event: 'legislature.inactivated',
            actor: $actor,
            subject: $currentLegislature,
            context: $context,
            oldValues: $oldValues,
            newValues: $this->legislatureSnapshot($currentLegislature),
        );
    }

    private function assertConsecutiveYears(int $startYear, int $endYear): void
    {
        if ($endYear !== $startYear + 1) {
            throw ValidationException::withMessages([
                'end_year' => 'El período debe terminar exactamente un año después del año de inicio.',
            ]);
        }
    }

    private function assertPeriodAvailable(int $startYear, int $endYear, ?int $exceptId = null): void
    {
        $query = Legislature::query()
            ->where('start_year', $startYear)
            ->where('end_year', $endYear);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'start_year' => 'Ya existe una legislatura para este período.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function legislatureSnapshot(Legislature $legislature): array
    {
        return [
            'id' => $legislature->id,
            'start_year' => $legislature->start_year,
            'end_year' => $legislature->end_year,
            'period_label' => $legislature->period_label,
            'status' => $legislature->status->value,
            'activated_at' => $legislature->activated_at?->toIso8601String(),
            'inactivated_at' => $legislature->inactivated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function boardAssignmentSnapshot(LegislatureBoardAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'legislature_id' => $assignment->legislature_id,
            'user_id' => $assignment->user_id,
            'position' => $assignment->position->value,
            'effective_on' => $assignment->effective_on->toDateString(),
            'effective_at' => $assignment->effective_at->toIso8601String(),
            'ended_on' => $assignment->ended_on?->toDateString(),
            'ended_at' => $assignment->ended_at?->toIso8601String(),
        ];
    }
}
