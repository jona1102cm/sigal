<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\DTOs\CreateUserData;
use App\Domain\Authorization\DTOs\EmergencyPasswordResetResult;
use App\Domain\Authorization\DTOs\UpdateUserData;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administra identidades, estados, recuperación de emergencia y roles históricos.
 *
 * Protege la continuidad institucional impidiendo perder al último
 * superadministrador activo, incluso frente a solicitudes concurrentes.
 */
class UserManagementService
{
    private const SUPER_ADMINISTRATION_LOCK_ID = 9_013_127;

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function create(CreateUserData $data, User $actor, RequestAuditContext $context): User
    {
        return DB::transaction(function () use ($data, $actor, $context): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'status' => UserStatus::Active,
            ]);

            $this->activityLogger->record(
                event: 'user.created',
                actor: $actor,
                subject: $user,
                context: $context,
                newValues: $this->userSnapshot($user),
            );

            return $user;
        });
    }

    public function update(User $user, UpdateUserData $data, User $actor, RequestAuditContext $context): User
    {
        return DB::transaction(function () use ($user, $data, $actor, $context): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldValues = $this->userSnapshot($target);

            $attributes = [
                'name' => $data->name,
                'email' => $data->email,
            ];

            if ($data->password !== null) {
                $attributes['password'] = $data->password;
            }

            $target->update($attributes);

            if ($data->password !== null) {
                $target->tokens()->delete();
            }

            $this->activityLogger->record(
                event: 'user.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->userSnapshot($target),
            );

            return $target;
        });
    }

    public function activate(User $user, User $actor, RequestAuditContext $context): User
    {
        return $this->changeStatus($user, UserStatus::Active, $actor, $context);
    }

    public function inactivate(User $user, User $actor, RequestAuditContext $context): User
    {
        return DB::transaction(function () use ($user, $actor, $context): User {
            $this->lockSuperAdministration();
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $target->isActive()) {
                return $target;
            }

            $this->assertCanRemoveActiveSuperAdministrator($target);
            $oldValues = $this->userSnapshot($target);

            $target->update(['status' => UserStatus::Inactive]);
            $target->tokens()->delete();

            $this->activityLogger->record(
                event: 'user.inactivated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->userSnapshot($target),
            );

            return $target;
        });
    }

    public function resetPassword(User $user, User $actor, RequestAuditContext $context): EmergencyPasswordResetResult
    {
        return DB::transaction(function () use ($user, $actor, $context): EmergencyPasswordResetResult {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($target->is($actor)) {
                throw ValidationException::withMessages([
                    'user' => 'Para cambiar su propia contraseña utilice la opción de seguridad de su cuenta.',
                ]);
            }

            $temporaryPassword = Str::password(24, symbols: true);
            $oldValues = [
                'must_change_password' => $target->must_change_password,
                'active_session_count' => $target->tokens()->count(),
            ];

            $target->update([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ]);
            $target->tokens()->delete();

            $this->activityLogger->record(
                event: 'user.emergency_password_reset',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: [
                    'must_change_password' => true,
                    'active_sessions_revoked' => $oldValues['active_session_count'],
                ],
            );

            return new EmergencyPasswordResetResult($target, $temporaryPassword);
        });
    }

    public function assignRole(User $user, RoleCode $roleCode, User $actor, RequestAuditContext $context): UserRoleAssignment
    {
        return DB::transaction(function () use ($user, $roleCode, $actor, $context): UserRoleAssignment {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = Role::query()->where('code', $roleCode->value)->firstOrFail();
            $now = now();

            $assignment = UserRoleAssignment::query()
                ->where('user_id', $target->id)
                ->where('role_id', $role->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($assignment !== null) {
                throw ValidationException::withMessages([
                    'role' => 'El usuario ya tiene este rol vigente.',
                ]);
            }

            $assignment = UserRoleAssignment::query()->create([
                'user_id' => $target->id,
                'role_id' => $role->id,
                'assigned_by' => $actor->id,
                'effective_from' => $now,
            ]);

            $this->activityLogger->record(
                event: 'user.role_assigned',
                actor: $actor,
                subject: $assignment,
                context: $context,
                newValues: $this->roleAssignmentSnapshot($assignment->load('role')),
            );

            return $assignment->load('role');
        });
    }

    public function removeRole(User $user, RoleCode $roleCode, User $actor, RequestAuditContext $context): UserRoleAssignment
    {
        return DB::transaction(function () use ($user, $roleCode, $actor, $context): UserRoleAssignment {
            if ($roleCode === RoleCode::SuperAdministrator) {
                $this->lockSuperAdministration();
            }

            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = Role::query()->where('code', $roleCode->value)->firstOrFail();
            $assignment = UserRoleAssignment::query()
                ->where('user_id', $target->id)
                ->where('role_id', $role->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw ValidationException::withMessages([
                    'role' => 'El usuario no tiene este rol vigente.',
                ]);
            }

            if ($roleCode === RoleCode::SuperAdministrator && $target->isActive()) {
                $this->assertCanRemoveActiveSuperAdministrator($target);
            }

            $oldValues = $this->roleAssignmentSnapshot($assignment->load('role'));
            $assignment->update(['effective_to' => now()]);

            $this->activityLogger->record(
                event: 'user.role_removed',
                actor: $actor,
                subject: $assignment,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->roleAssignmentSnapshot($assignment),
            );

            return $assignment;
        });
    }

    private function changeStatus(User $user, UserStatus $status, User $actor, RequestAuditContext $context): User
    {
        return DB::transaction(function () use ($user, $status, $actor, $context): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($target->status === $status) {
                return $target;
            }

            $oldValues = $this->userSnapshot($target);
            $target->update(['status' => $status]);

            $this->activityLogger->record(
                event: 'user.activated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->userSnapshot($target),
            );

            return $target;
        });
    }

    private function assertCanRemoveActiveSuperAdministrator(User $user): void
    {
        if (! $user->hasActiveRole(RoleCode::SuperAdministrator)) {
            return;
        }

        $anotherActiveSuperAdministratorExists = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($user->id)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now()))
                ->whereHas('role', fn ($query) => $query->where('code', RoleCode::SuperAdministrator->value)))
            ->exists();

        if (! $anotherActiveSuperAdministratorExists) {
            throw ValidationException::withMessages([
                'user' => 'Debe existir al menos un superadministrador activo para conservar el acceso institucional.',
            ]);
        }
    }

    private function lockSuperAdministration(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::SUPER_ADMINISTRATION_LOCK_ID]);
    }

    /** @return array<string, mixed> */
    private function userSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function roleAssignmentSnapshot(UserRoleAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'role' => $assignment->role->code,
            'effective_from' => $assignment->effective_from->toIso8601String(),
            'effective_to' => $assignment->effective_to?->toIso8601String(),
        ];
    }
}
