<?php

namespace App\Domain\HumanResources\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Enums\UserStatus;
use App\Domain\HumanResources\DTOs\CreateOfficePositionData;
use App\Domain\HumanResources\DTOs\EmployeeRegistrationResult;
use App\Domain\HumanResources\DTOs\RegisterEmployeeContractData;
use App\Domain\HumanResources\DTOs\UpdateEmployeeData;
use App\Domain\HumanResources\DTOs\UpdateOfficePositionData;
use App\Domain\HumanResources\Enums\EmployeeAttachmentType;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Employee;
use App\Models\EmployeeAttachment;
use App\Models\EmploymentContract;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OfficePosition;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HumanResourcesService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * @param  array<string, UploadedFile|null>  $attachments
     */
    public function registerEmployeeAndContract(
        RegisterEmployeeContractData $data,
        array $attachments,
        User $actor,
        RequestAuditContext $context,
    ): EmployeeRegistrationResult {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($data, $attachments, $actor, $context, &$storedPaths): EmployeeRegistrationResult {
                $this->assertAssignableRole($data->role, $actor);

                $employee = Employee::query()
                    ->where('identity_card', $data->identityCard)
                    ->lockForUpdate()
                    ->first();
                $isNewEmployee = $employee === null;

                if ($employee === null) {
                    $employee = Employee::query()->create([
                        ...$this->employeeAttributes($data),
                        'created_by' => $actor->id,
                    ]);
                    $this->activityLogger->record(
                        event: 'human_resources.employee.created',
                        actor: $actor,
                        subject: $employee,
                        context: $context,
                        newValues: $this->employeeSnapshot($employee),
                    );
                } else {
                    $oldValues = $this->employeeSnapshot($employee);
                    $employee->update($this->employeeAttributes($data));
                    $this->activityLogger->record(
                        event: 'human_resources.employee.updated_during_registration',
                        actor: $actor,
                        subject: $employee,
                        context: $context,
                        oldValues: $oldValues,
                        newValues: $this->employeeSnapshot($employee),
                    );
                }

                $openContract = EmploymentContract::query()
                    ->where('employee_id', $employee->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();

                if ($openContract !== null) {
                    if ($openContract->ends_on !== null && $openContract->ends_on->isBefore(today())) {
                        $this->finishLockedContract($openContract, null, $context, true);
                    } else {
                        throw ValidationException::withMessages([
                            'identity_card' => 'La persona ya tiene un contrato vigente. Extienda o finalice ese contrato antes de registrar uno nuevo.',
                        ]);
                    }
                }

                $position = OfficePosition::query()
                    ->with('office')
                    ->lockForUpdate()
                    ->findOrFail($data->officePositionId);

                if ($position->office->status->value !== 'active' || ! $position->office->supports_staffing) {
                    throw ValidationException::withMessages([
                        'office_position_id' => 'Solo se pueden registrar contratos en oficinas activas que admiten funcionarios.',
                    ]);
                }

                $contract = EmploymentContract::query()->create([
                    'employee_id' => $employee->id,
                    'office_position_id' => $position->id,
                    'contract_type' => $data->contractType,
                    'contract_amount' => $data->contractAmount,
                    'starts_on' => $data->startsOn,
                    'ends_on' => $data->endsOn,
                    'created_by' => $actor->id,
                ]);

                $user = User::query()
                    ->where('employee_id', $employee->id)
                    ->lockForUpdate()
                    ->first();
                $temporaryPassword = null;

                if ($user === null) {
                    if ($data->email !== null && User::query()->where('email', $data->email)->exists()) {
                        throw ValidationException::withMessages([
                            'email' => 'Ese correo ya está asignado a otra cuenta SIGAL.',
                        ]);
                    }

                    $temporaryPassword = Str::password(20, symbols: true);
                    $user = User::query()->create([
                        'employee_id' => $employee->id,
                        'name' => $employee->fullName(),
                        'email' => $data->email ?? $this->generatedInstitutionalEmail($employee->identity_card),
                        'password' => $temporaryPassword,
                        'status' => $this->shouldHaveAccessNow($contract) ? UserStatus::Active : UserStatus::Inactive,
                        'must_change_password' => true,
                    ]);

                    $this->activityLogger->record(
                        event: 'human_resources.account.created',
                        actor: $actor,
                        subject: $user,
                        context: $context,
                        newValues: $this->userSnapshot($user),
                    );
                } else {
                    $oldValues = $this->userSnapshot($user);
                    $user->update(['name' => $employee->fullName()]);

                    if ($this->shouldHaveAccessNow($contract) && ! $user->isActive()) {
                        $user->update(['status' => UserStatus::Active]);
                        $this->activityLogger->record(
                            event: 'human_resources.account.reactivated',
                            actor: $actor,
                            subject: $user,
                            context: $context,
                            oldValues: $oldValues,
                            newValues: $this->userSnapshot($user),
                        );
                    }
                }

                $role = Role::query()->where('code', $data->role->value)->firstOrFail();
                $roleAssignment = UserRoleAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('role_id', $role->id)
                    ->whereNull('effective_to')
                    ->lockForUpdate()
                    ->first();

                if ($roleAssignment === null) {
                    $roleAssignment = UserRoleAssignment::query()->create([
                        'employment_contract_id' => $contract->id,
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                        'assigned_by' => $actor->id,
                        'effective_from' => $this->startOfContract($contract),
                    ]);

                    $this->activityLogger->record(
                        event: 'human_resources.contract_role_assigned',
                        actor: $actor,
                        subject: $roleAssignment,
                        context: $context,
                        newValues: [
                            'employment_contract_id' => $contract->id,
                            'user_id' => $user->id,
                            'role' => $role->code,
                            'effective_from' => $roleAssignment->effective_from->toIso8601String(),
                        ],
                    );
                }

                if (OfficeMembership::query()
                    ->where('office_id', $position->office_id)
                    ->where('user_id', $user->id)
                    ->whereNull('effective_to')
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'office_position_id' => 'El usuario ya tiene una asignación vigente en esta oficina. Cierre la asignación anterior antes de crear este contrato.',
                    ]);
                }

                $membership = OfficeMembership::query()->create([
                    'employment_contract_id' => $contract->id,
                    'office_id' => $position->office_id,
                    'user_id' => $user->id,
                    'membership_role' => $position->membership_role,
                    'position_title' => $position->name,
                    'effective_from' => $this->startOfContract($contract),
                    'assigned_by' => $actor->id,
                ]);

                foreach ($attachments as $field => $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }

                    $this->persistAttachment(
                        $employee,
                        $this->attachmentTypeForField($field),
                        $file,
                        $actor,
                        $context,
                        $storedPaths,
                    );
                }

                $contract->setRelation('employee', $employee);
                $contract->setRelation('officePosition', $position);
                $contract->setRelation('officeMembership', $membership);

                $this->activityLogger->record(
                    event: $isNewEmployee ? 'human_resources.contract.created' : 'human_resources.contract.renewed',
                    actor: $actor,
                    subject: $contract,
                    context: $context,
                    newValues: $this->contractSnapshot($contract),
                );

                return new EmployeeRegistrationResult($employee, $contract, $user, $temporaryPassword);
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    public function createOfficePosition(
        CreateOfficePositionData $data,
        User $actor,
        RequestAuditContext $context,
    ): OfficePosition {
        return DB::transaction(function () use ($data, $actor, $context): OfficePosition {
            $office = Office::query()->lockForUpdate()->findOrFail($data->officeId);

            if ($office->status->value !== 'active' || ! $office->supports_staffing) {
                throw ValidationException::withMessages([
                    'office_id' => 'Solo se pueden crear cargos para oficinas activas que admiten funcionarios.',
                ]);
            }

            $this->assertPositionRoleAllowed($office, $data->membershipRole);

            $position = OfficePosition::query()->create([
                'office_id' => $office->id,
                'name' => $data->name,
                'membership_role' => $data->membershipRole,
                'created_by' => $actor->id,
            ]);

            $this->activityLogger->record(
                event: 'human_resources.office_position.created',
                actor: $actor,
                subject: $position,
                context: $context,
                newValues: $this->positionSnapshot($position->load('office')),
            );

            return $position->load('office');
        });
    }

    public function updateOfficePosition(
        OfficePosition $position,
        UpdateOfficePositionData $data,
        User $actor,
        RequestAuditContext $context,
    ): OfficePosition {
        return DB::transaction(function () use ($position, $data, $actor, $context): OfficePosition {
            $target = OfficePosition::query()->with('office')->lockForUpdate()->findOrFail($position->id);

            if ($target->office->status->value !== 'active' || ! $target->office->supports_staffing) {
                throw ValidationException::withMessages([
                    'office_position' => 'Solo se pueden corregir cargos de oficinas activas que admiten funcionarios.',
                ]);
            }

            $this->assertPositionRoleAllowed($target->office, $data->membershipRole);
            $oldValues = $this->positionSnapshot($target);
            $target->update([
                'name' => $data->name,
                'membership_role' => $data->membershipRole,
            ]);

            $contractIds = EmploymentContract::query()
                ->where('office_position_id', $target->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->pluck('id');
            $memberships = OfficeMembership::query()
                ->whereIn('employment_contract_id', $contractIds)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->get();

            foreach ($memberships as $membership) {
                $membership->update([
                    'membership_role' => $data->membershipRole,
                    'position_title' => $data->name,
                ]);
            }

            $this->activityLogger->record(
                event: 'human_resources.office_position.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: [
                    ...$this->positionSnapshot($target->load('office')),
                    'updated_current_membership_ids' => $memberships->pluck('id')->all(),
                ],
            );

            return $target->load('office');
        });
    }

    public function uploadProfilePhoto(
        Employee $employee,
        UploadedFile $photo,
        User $actor,
        RequestAuditContext $context,
    ): EmployeeAttachment {
        return $this->uploadEmployeeAttachment(
            $employee,
            EmployeeAttachmentType::ProfilePhoto,
            $photo,
            $actor,
            $context,
        );
    }

    public function uploadEmployeeAttachment(
        Employee $employee,
        EmployeeAttachmentType $attachmentType,
        UploadedFile $file,
        User $actor,
        RequestAuditContext $context,
    ): EmployeeAttachment {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($employee, $attachmentType, $file, $actor, $context, &$storedPaths): EmployeeAttachment {
                $target = Employee::query()->lockForUpdate()->findOrFail($employee->id);

                return $this->persistAttachment(
                    $target,
                    $attachmentType,
                    $file,
                    $actor,
                    $context,
                    $storedPaths,
                );
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    public function updateEmployee(
        Employee $employee,
        UpdateEmployeeData $data,
        User $actor,
        RequestAuditContext $context,
    ): Employee {
        return DB::transaction(function () use ($employee, $data, $actor, $context): Employee {
            $target = Employee::query()->with('user')->lockForUpdate()->findOrFail($employee->id);
            $oldValues = $this->employeeSnapshot($target);
            $target->update($this->employeeAttributes($data));

            if ($target->user !== null) {
                $target->user->update(['name' => $target->fullName()]);
            }

            $this->activityLogger->record(
                event: 'human_resources.employee.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->employeeSnapshot($target),
            );

            return $target;
        });
    }

    public function extendContract(
        EmploymentContract $contract,
        CarbonImmutable $endsOn,
        User $actor,
        RequestAuditContext $context,
    ): EmploymentContract {
        return DB::transaction(function () use ($contract, $endsOn, $actor, $context): EmploymentContract {
            $target = EmploymentContract::query()
                ->with(['employee', 'officePosition.office'])
                ->lockForUpdate()
                ->findOrFail($contract->id);

            if (! $target->isOpen()) {
                throw ValidationException::withMessages([
                    'contract' => 'No se puede extender un contrato finalizado.',
                ]);
            }

            if ($target->ends_on === null) {
                throw ValidationException::withMessages([
                    'ends_on' => 'El contrato no tiene una fecha de finalización para extender.',
                ]);
            }

            if ($endsOn->lessThanOrEqualTo($target->ends_on)) {
                throw ValidationException::withMessages([
                    'ends_on' => 'La nueva fecha debe ser posterior a la fecha de fin actual.',
                ]);
            }

            $oldValues = $this->contractSnapshot($target);
            $target->update(['ends_on' => $endsOn]);

            $this->activityLogger->record(
                event: 'human_resources.contract.extended',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->contractSnapshot($target),
            );

            return $target;
        });
    }

    public function finishContract(
        EmploymentContract $contract,
        User $actor,
        RequestAuditContext $context,
    ): EmploymentContract {
        return DB::transaction(function () use ($contract, $actor, $context): EmploymentContract {
            $target = EmploymentContract::query()->lockForUpdate()->findOrFail($contract->id);

            if (! $target->isOpen()) {
                throw ValidationException::withMessages([
                    'contract' => 'El contrato ya se encuentra finalizado.',
                ]);
            }

            if ($target->starts_on->isAfter(today())) {
                throw ValidationException::withMessages([
                    'contract' => 'Un contrato programado no puede finalizarse antes de su fecha de inicio.',
                ]);
            }

            return $this->finishLockedContract($target, $actor, $context, false);
        });
    }

    public function expireDueContracts(): int
    {
        $contractIds = EmploymentContract::query()
            ->whereNull('ended_at')
            ->whereNotNull('ends_on')
            ->where('ends_on', '<', today())
            ->pluck('id');
        $expired = 0;

        foreach ($contractIds as $contractId) {
            DB::transaction(function () use ($contractId, &$expired): void {
                $contract = EmploymentContract::query()->lockForUpdate()->findOrFail($contractId);

                if ($contract->isOpen() && $contract->ends_on !== null && $contract->ends_on->isBefore(today())) {
                    $this->finishLockedContract($contract, null, new RequestAuditContext(null, 'scheduler'), true);
                    $expired++;
                }
            });
        }

        return $expired;
    }

    public function activateStartingContracts(): int
    {
        $contracts = EmploymentContract::query()
            ->with('employee.user')
            ->whereNull('ended_at')
            ->where('starts_on', '<=', today())
            ->get();
        $activated = 0;

        foreach ($contracts as $contract) {
            DB::transaction(function () use ($contract, &$activated): void {
                $target = EmploymentContract::query()->with('employee.user')->lockForUpdate()->findOrFail($contract->id);
                $user = $target->employee->user;

                if ($target->isOpen() && $this->shouldHaveAccessNow($target) && $user !== null && ! $user->isActive()) {
                    $oldValues = $this->userSnapshot($user);
                    $user->update(['status' => UserStatus::Active]);
                    $this->activityLogger->record(
                        event: 'human_resources.account.activated_on_contract_start',
                        actor: null,
                        subject: $user,
                        context: new RequestAuditContext(null, 'scheduler'),
                        oldValues: $oldValues,
                        newValues: $this->userSnapshot($user),
                    );
                    $activated++;
                }
            });
        }

        return $activated;
    }

    private function finishLockedContract(
        EmploymentContract $contract,
        ?User $actor,
        RequestAuditContext $context,
        bool $expired,
    ): EmploymentContract {
        $target = EmploymentContract::query()
            ->with(['employee.user', 'officePosition.office', 'officeMembership', 'roleAssignments.role'])
            ->lockForUpdate()
            ->findOrFail($contract->id);

        if (! $target->isOpen()) {
            return $target;
        }

        $oldValues = $this->contractSnapshot($target);
        $endedAt = now();
        $target->update([
            'ends_on' => $expired ? $target->ends_on : $endedAt->toDateString(),
            'ended_at' => $endedAt,
            'ended_by' => $actor?->id,
        ]);

        $membership = OfficeMembership::query()
            ->where('employment_contract_id', $target->id)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->first();
        if ($membership !== null) {
            $membership->update(['effective_to' => $endedAt]);
        }

        UserRoleAssignment::query()
            ->where('employment_contract_id', $target->id)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->each(fn (UserRoleAssignment $assignment) => $assignment->update(['effective_to' => $endedAt]));

        $user = $target->employee->user;
        if ($user !== null && ! EmploymentContract::query()
            ->where('employee_id', $target->employee_id)
            ->whereKeyNot($target->id)
            ->whereNull('ended_at')
            ->exists()) {
            $oldUserValues = $this->userSnapshot($user);
            $user->update(['status' => UserStatus::Inactive]);
            $user->tokens()->delete();
            $this->activityLogger->record(
                event: 'human_resources.account.inactivated_on_contract_end',
                actor: $actor,
                subject: $user,
                context: $context,
                oldValues: $oldUserValues,
                newValues: $this->userSnapshot($user),
            );
        }

        $this->activityLogger->record(
            event: $expired ? 'human_resources.contract.expired' : 'human_resources.contract.finished',
            actor: $actor,
            subject: $target,
            context: $context,
            oldValues: $oldValues,
            newValues: $this->contractSnapshot($target),
        );

        return $target;
    }

    /** @return array<string, mixed> */
    private function employeeAttributes(RegisterEmployeeContractData|UpdateEmployeeData $data): array
    {
        return [
            'identity_card' => $data->identityCard,
            'first_names' => $this->uppercase($data->firstNames),
            'last_names' => $this->uppercase($data->lastNames),
            'mobile_phone' => $data->mobilePhone,
            'email' => $data->email,
            'address' => $data->address,
            'cua_number' => $data->cuaNumber,
            'birth_date' => $data->birthDate,
            'military_service_booklet' => $data->militaryServiceBooklet,
            'academic_degree' => $data->academicDegree,
            'profession' => $data->profession,
            'blood_type' => $data->bloodType,
            'emergency_contact' => $data->emergencyContact,
        ];
    }

    private function generatedInstitutionalEmail(string $identityCard): string
    {
        $localPart = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $identityCard));
        $localPart = $localPart !== '' ? $localPart : 'funcionario';
        $candidate = "{$localPart}@sigal.local";
        $suffix = 2;

        while (User::query()->where('email', $candidate)->exists()) {
            $candidate = "{$localPart}-{$suffix}@sigal.local";
            $suffix++;
        }

        return $candidate;
    }

    private function shouldHaveAccessNow(EmploymentContract $contract): bool
    {
        return $contract->isOpen()
            && $contract->starts_on->lessThanOrEqualTo(today())
            && ($contract->ends_on === null || $contract->ends_on->greaterThanOrEqualTo(today()));
    }

    private function startOfContract(EmploymentContract $contract): CarbonImmutable
    {
        return CarbonImmutable::parse($contract->starts_on->format('Y-m-d'), 'America/La_Paz')->startOfDay();
    }

    private function assertAssignableRole(RoleCode $role, User $actor): void
    {
        if ($role === RoleCode::SuperAdministrator && ! $actor->isSuperAdministrator()) {
            throw ValidationException::withMessages([
                'role' => 'Solo un superadministrador puede asignar ese rol.',
            ]);
        }
    }

    private function assertPositionRoleAllowed(Office $office, OfficeMembershipRole $membershipRole): void
    {
        if ($membershipRole->value === 'manager' && ! $office->requires_manager) {
            throw ValidationException::withMessages([
                'membership_role' => 'Esta oficina no tiene responsable propio en el organigrama institucional.',
            ]);
        }
    }

    private function attachmentTypeForField(string $field): EmployeeAttachmentType
    {
        return match ($field) {
            'rejap_certificate' => EmployeeAttachmentType::RejapCertificate,
            'cenvi_certificate' => EmployeeAttachmentType::CenviCertificate,
            'electoral_registry_certificate' => EmployeeAttachmentType::ElectoralRegistryCertificate,
            'profile_photo' => EmployeeAttachmentType::ProfilePhoto,
        };
    }

    /**
     * @param  array<int, string>  $storedPaths
     */
    private function persistAttachment(
        Employee $employee,
        EmployeeAttachmentType $attachmentType,
        UploadedFile $file,
        User $actor,
        RequestAuditContext $context,
        array &$storedPaths,
    ): EmployeeAttachment {
        $sha256 = hash_file('sha256', $file->getRealPath());

        $existing = EmployeeAttachment::query()
            ->where('employee_id', $employee->id)
            ->where('document_type', $attachmentType->value)
            ->where('sha256', $sha256)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $path = $this->storeAttachment($employee, $file);
        $storedPaths[] = $path;
        $attachment = EmployeeAttachment::query()->create([
            'employee_id' => $employee->id,
            'document_type' => $attachmentType,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ]);

        $this->activityLogger->record(
            event: 'human_resources.employee_attachment.uploaded',
            actor: $actor,
            subject: $attachment,
            context: $context,
            newValues: $this->attachmentSnapshot($attachment),
        );

        return $attachment;
    }

    private function uppercase(string $value): string
    {
        return mb_strtoupper(trim($value), 'UTF-8');
    }

    private function storeAttachment(Employee $employee, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $directory = "human-resources/employees/{$employee->id}";
        $name = Str::uuid().".{$extension}";

        return Storage::disk('local')->putFileAs($directory, $file, $name);
    }

    /** @return array<string, mixed> */
    private function employeeSnapshot(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'identity_card' => $employee->identity_card,
            'first_names' => $employee->first_names,
            'last_names' => $employee->last_names,
            'mobile_phone' => $employee->mobile_phone,
            'email' => $employee->email,
        ];
    }

    /** @return array<string, mixed> */
    private function userSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function positionSnapshot(OfficePosition $position): array
    {
        return [
            'id' => $position->id,
            'office_id' => $position->office_id,
            'office_code' => $position->office->code,
            'name' => $position->name,
            'membership_role' => $position->membership_role->value,
        ];
    }

    /** @return array<string, mixed> */
    private function contractSnapshot(EmploymentContract $contract): array
    {
        return [
            'id' => $contract->id,
            'employee_id' => $contract->employee_id,
            'office_position_id' => $contract->office_position_id,
            'office_id' => $contract->officePosition?->office_id,
            'contract_type' => $contract->contract_type->value,
            'contract_amount' => $contract->contract_amount,
            'starts_on' => $contract->starts_on->toDateString(),
            'ends_on' => $contract->ends_on?->toDateString(),
            'ended_at' => $contract->ended_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function attachmentSnapshot(EmployeeAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'employee_id' => $attachment->employee_id,
            'document_type' => $attachment->document_type->value,
            'original_name' => $attachment->original_name,
            'sha256' => $attachment->sha256,
            'size_bytes' => $attachment->size_bytes,
        ];
    }
}
