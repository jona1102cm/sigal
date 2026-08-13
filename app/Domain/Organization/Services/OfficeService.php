<?php

namespace App\Domain\Organization\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\UserStatus;
use App\Domain\Organization\DTOs\AssignOfficeMembershipData;
use App\Domain\Organization\DTOs\CreateOfficeData;
use App\Domain\Organization\DTOs\UpdateOfficeData;
use App\Domain\Organization\Enums\OfficeStatus;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mantiene el árbol institucional y las membresías históricas de cada oficina.
 *
 * Valida jerarquía, capacidad de dotación y cierres sin borrar las relaciones
 * utilizadas posteriormente por permisos y tenencia documental.
 */
class OfficeService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function create(CreateOfficeData $data, User $actor, RequestAuditContext $context): Office
    {
        return DB::transaction(function () use ($data, $actor, $context): Office {
            $this->assertValidParent($data->parentId, null);
            $this->assertValidStaffingProfile($data->supportsStaffing, $data->requiresManager);

            $office = Office::query()->create([
                'parent_id' => $data->parentId,
                'code' => $data->code,
                'name' => $data->name,
                'status' => $data->status,
                'supports_staffing' => $data->supportsStaffing,
                'requires_manager' => $data->requiresManager,
            ]);

            $this->activityLogger->record(
                event: 'organization.office.created',
                actor: $actor,
                subject: $office,
                context: $context,
                newValues: $this->officeSnapshot($office),
            );

            return $office;
        });
    }

    public function update(Office $office, UpdateOfficeData $data, User $actor, RequestAuditContext $context): Office
    {
        return DB::transaction(function () use ($office, $data, $actor, $context): Office {
            $target = Office::query()->lockForUpdate()->findOrFail($office->id);
            $this->assertValidParent($data->parentId, $target->id);
            $this->assertValidStaffingProfile($data->supportsStaffing, $data->requiresManager);

            if (! $data->supportsStaffing && $target->currentMemberships()->exists()) {
                throw ValidationException::withMessages([
                    'supports_staffing' => 'Cierre las asignaciones vigentes antes de convertir la unidad en un nodo sin personal.',
                ]);
            }
            $oldValues = $this->officeSnapshot($target);

            $target->update([
                'parent_id' => $data->parentId,
                'code' => $data->code,
                'name' => $data->name,
                'supports_staffing' => $data->supportsStaffing,
                'requires_manager' => $data->requiresManager,
            ]);

            $this->activityLogger->record(
                event: 'organization.office.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->officeSnapshot($target),
            );

            return $target;
        });
    }

    public function activate(Office $office, User $actor, RequestAuditContext $context): Office
    {
        return $this->changeStatus($office, OfficeStatus::Active, $actor, $context);
    }

    public function inactivate(Office $office, User $actor, RequestAuditContext $context): Office
    {
        return DB::transaction(function () use ($office, $actor, $context): Office {
            $target = Office::query()->lockForUpdate()->findOrFail($office->id);

            if ($target->status === OfficeStatus::Inactive) {
                return $target;
            }

            if ($target->children()->active()->exists()) {
                throw ValidationException::withMessages([
                    'office' => 'No se puede inactivar una oficina que tiene suboficinas activas.',
                ]);
            }

            if ($target->currentMemberships()->exists()) {
                throw ValidationException::withMessages([
                    'office' => 'Cierre las membresías vigentes antes de inactivar la oficina.',
                ]);
            }

            $oldValues = $this->officeSnapshot($target);
            $target->update(['status' => OfficeStatus::Inactive]);

            $this->activityLogger->record(
                event: 'organization.office.inactivated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->officeSnapshot($target),
            );

            return $target;
        });
    }

    public function assignMembership(
        Office $office,
        AssignOfficeMembershipData $data,
        User $actor,
        RequestAuditContext $context,
    ): OfficeMembership {
        return DB::transaction(function () use ($office, $data, $actor, $context): OfficeMembership {
            $target = Office::query()->lockForUpdate()->findOrFail($office->id);

            if ($target->status !== OfficeStatus::Active) {
                throw ValidationException::withMessages([
                    'office' => 'Solo se pueden asignar miembros a oficinas activas.',
                ]);
            }

            if (! $target->supports_staffing) {
                throw ValidationException::withMessages([
                    'office' => 'Este nodo organizacional no admite asignaciones de funcionarios.',
                ]);
            }

            $user = User::query()->lockForUpdate()->findOrFail($data->userId);

            if ($user->status !== UserStatus::Active) {
                throw ValidationException::withMessages([
                    'user_id' => 'Solo se pueden asignar usuarios activos a una oficina.',
                ]);
            }

            if ($target->currentMemberships()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => 'El usuario ya tiene una membresía vigente en esta oficina.',
                ]);
            }

            $membership = OfficeMembership::query()->create([
                'office_id' => $target->id,
                'user_id' => $user->id,
                'membership_role' => $data->membershipRole,
                'position_title' => $data->positionTitle,
                'effective_from' => now(),
                'assigned_by' => $actor->id,
            ]);

            $this->activityLogger->record(
                event: 'organization.office_membership.assigned',
                actor: $actor,
                subject: $membership,
                context: $context,
                newValues: $this->membershipSnapshot($membership->load(['office', 'user'])),
            );

            return $membership->load(['office', 'user']);
        });
    }

    public function closeMembership(
        Office $office,
        OfficeMembership $membership,
        User $actor,
        RequestAuditContext $context,
    ): OfficeMembership {
        return DB::transaction(function () use ($office, $membership, $actor, $context): OfficeMembership {
            $target = OfficeMembership::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->findOrFail($membership->id);

            if ($target->effective_to !== null) {
                throw ValidationException::withMessages([
                    'membership' => 'La membresía ya se encuentra cerrada.',
                ]);
            }

            $oldValues = $this->membershipSnapshot($target->load(['office', 'user']));
            $target->update(['effective_to' => now()]);

            $this->activityLogger->record(
                event: 'organization.office_membership.closed',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->membershipSnapshot($target),
            );

            return $target;
        });
    }

    private function changeStatus(Office $office, OfficeStatus $status, User $actor, RequestAuditContext $context): Office
    {
        return DB::transaction(function () use ($office, $status, $actor, $context): Office {
            $target = Office::query()->lockForUpdate()->findOrFail($office->id);

            if ($target->status === $status) {
                return $target;
            }

            $this->assertValidParent($target->parent_id, $target->id);
            $oldValues = $this->officeSnapshot($target);
            $target->update(['status' => $status]);

            $this->activityLogger->record(
                event: 'organization.office.activated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->officeSnapshot($target),
            );

            return $target;
        });
    }

    private function assertValidParent(?int $parentId, ?int $officeId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $officeId) {
            throw ValidationException::withMessages([
                'parent_id' => 'Una oficina no puede depender de sí misma.',
            ]);
        }

        $parent = Office::query()->lockForUpdate()->findOrFail($parentId);

        if ($parent->status !== OfficeStatus::Active) {
            throw ValidationException::withMessages([
                'parent_id' => 'La oficina padre debe estar activa.',
            ]);
        }

        while ($parent->parent_id !== null) {
            if ($parent->parent_id === $officeId) {
                throw ValidationException::withMessages([
                    'parent_id' => 'No se puede crear un ciclo en la estructura organizacional.',
                ]);
            }

            $parent = Office::query()->lockForUpdate()->findOrFail($parent->parent_id);
        }
    }

    private function assertValidStaffingProfile(bool $supportsStaffing, bool $requiresManager): void
    {
        if (! $supportsStaffing && $requiresManager) {
            throw ValidationException::withMessages([
                'requires_manager' => 'Un nodo sin personal no puede requerir responsable de oficina.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function officeSnapshot(Office $office): array
    {
        return [
            'id' => $office->id,
            'parent_id' => $office->parent_id,
            'code' => $office->code,
            'name' => $office->name,
            'status' => $office->status->value,
            'supports_staffing' => $office->supports_staffing,
            'requires_manager' => $office->requires_manager,
        ];
    }

    /** @return array<string, mixed> */
    private function membershipSnapshot(OfficeMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'office_id' => $membership->office_id,
            'office_code' => $membership->office->code,
            'user_id' => $membership->user_id,
            'user_name' => $membership->user->name,
            'membership_role' => $membership->membership_role->value,
            'position_title' => $membership->position_title,
            'effective_from' => $membership->effective_from->toIso8601String(),
            'effective_to' => $membership->effective_to?->toIso8601String(),
        ];
    }
}
