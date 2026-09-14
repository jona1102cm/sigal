<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\Enums\OfficeDocumentAccessMode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Expedient;
use App\Models\ExpedientInternalAssignment;
use App\Models\Office;
use App\Models\OfficeDocumentAccessAuthorization;
use App\Models\OfficeDocumentAccessSetting;
use App\Models\OfficeMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resuelve el acceso operativo de una oficina sin confundirlo con permisos de rol.
 * Una Policy exige ambas condiciones antes de exponer o modificar un expediente.
 */
class OfficeDocumentAccessService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function modeFor(Office $office): OfficeDocumentAccessMode
    {
        if (! $office->requires_manager) {
            return OfficeDocumentAccessMode::AllMembers;
        }

        $setting = $office->relationLoaded('documentAccessSetting')
            ? $office->documentAccessSetting
            : $office->documentAccessSetting()->first();

        return $setting?->mode ?? OfficeDocumentAccessMode::ManagerAssignment;
    }

    /** Oficinas donde el usuario obtiene acceso automático a todos los expedientes relacionados. @return Collection<int, int> */
    public function automaticOfficeIds(User $user): Collection
    {
        return $user->currentOfficeMemberships()
            ->with(['office.documentAccessSetting.currentAuthorizations'])
            ->get()
            ->filter(function (OfficeMembership $membership) use ($user): bool {
                if ($membership->membership_role === OfficeMembershipRole::Manager) {
                    return true;
                }

                $office = $membership->office;
                $mode = $this->modeFor($office);

                return $mode === OfficeDocumentAccessMode::AllMembers
                    || ($mode === OfficeDocumentAccessMode::AuthorizedTeam
                        && $office->documentAccessSetting?->currentAuthorizations
                            ->contains(fn (OfficeDocumentAccessAuthorization $authorization) => (int) $authorization->user_id === (int) $user->id));
            })
            ->pluck('office_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function hasInternalAccess(User $user, Expedient $expedient): bool
    {
        $currentOfficeIds = $user->currentOfficeMemberships()->pluck('office_id');
        if ($currentOfficeIds->isEmpty()) {
            return false;
        }

        return ExpedientInternalAssignment::query()
            ->where('user_id', $user->id)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('recipient', fn ($recipient) => $recipient
                ->whereIn('recipient_office_id', $currentOfficeIds)
                ->whereHas('movement', fn ($movement) => $movement->where('expedient_id', $expedient->id)))
            ->exists();
    }

    public function canOperateExpedient(User $user, Expedient $expedient): bool
    {
        $holderOfficeIds = $expedient->currentHolderOfficeIds();
        $memberships = $user->currentOfficeMemberships()
            ->whereIn('office_id', $holderOfficeIds)
            ->with(['office.documentAccessSetting.currentAuthorizations'])
            ->get();

        foreach ($memberships as $membership) {
            $office = $membership->office;
            $mode = $this->modeFor($office);

            if ($mode === OfficeDocumentAccessMode::ManagerAssignment) {
                // Mientras la jefatura aún no distribuyó el ingreso, ella es el único
                // actor posible. Al designar responsable, la exclusividad pasa a ese
                // funcionario y la jefatura conserva únicamente lectura y reasignación.
                if ($this->hasAnyResponsibleAssignment($expedient, (int) $membership->office_id)) {
                    if ($this->hasResponsibleAssignment($user, $expedient, (int) $membership->office_id)) {
                        return true;
                    }

                    continue;
                }

                if ($membership->membership_role === OfficeMembershipRole::Manager) {
                    return true;
                }

                continue;
            }

            if ($membership->membership_role === OfficeMembershipRole::Manager) {
                return true;
            }

            if ($mode === OfficeDocumentAccessMode::AllMembers) {
                return true;
            }

            if ($mode === OfficeDocumentAccessMode::AuthorizedTeam
                && $office->documentAccessSetting?->currentAuthorizations
                    ->contains(fn ($authorization) => (int) $authorization->user_id === (int) $user->id)) {
                return true;
            }
        }

        return false;
    }

    public function canManageInternalAssignments(User $user, Expedient $expedient): bool
    {
        return $this->manageableInternalAssignmentOfficeIds($user, $expedient)->isNotEmpty();
    }

    /** @return Collection<int, int> */
    public function manageableInternalAssignmentOfficeIds(User $user, Expedient $expedient): Collection
    {
        $individualOfficeIds = Office::query()
            ->whereIn('id', $expedient->currentHolderOfficeIds())
            ->where('requires_manager', true)
            ->with('documentAccessSetting')
            ->get()
            ->filter(fn (Office $office) => $this->modeFor($office) === OfficeDocumentAccessMode::ManagerAssignment)
            ->pluck('id');

        if ($individualOfficeIds->isEmpty()) {
            return collect();
        }

        if ($user->isSuperAdministrator()) {
            return $individualOfficeIds->map(fn ($id) => (int) $id)->values();
        }

        return $user->currentOfficeMemberships()
            ->whereIn('office_id', $individualOfficeIds)
            ->where('membership_role', OfficeMembershipRole::Manager->value)
            ->whereHas('office', fn ($office) => $office->where('requires_manager', true))
            ->pluck('office_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** @param list<int> $authorizedUserIds */
    public function updateSetting(
        Office $office,
        OfficeDocumentAccessMode $mode,
        array $authorizedUserIds,
        User $actor,
        RequestAuditContext $context,
    ): OfficeDocumentAccessSetting {
        return DB::transaction(function () use ($office, $mode, $authorizedUserIds, $actor, $context): OfficeDocumentAccessSetting {
            $targetOffice = Office::query()->lockForUpdate()->findOrFail($office->id);
            if (! $targetOffice->requires_manager) {
                throw ValidationException::withMessages([
                    'mode' => 'Esta oficina no posee responsable propio; todos sus miembros conservan acceso por regla institucional.',
                ]);
            }

            if ($mode === OfficeDocumentAccessMode::AllMembers) {
                throw ValidationException::withMessages(['mode' => 'Seleccione uno de los dos modos administrados por jefatura.']);
            }

            $setting = OfficeDocumentAccessSetting::query()->firstOrCreate(
                ['office_id' => $targetOffice->id],
                ['mode' => OfficeDocumentAccessMode::ManagerAssignment, 'configured_by' => $actor->id],
            );
            $setting->load('currentAuthorizations');
            $oldValues = [
                'mode' => $setting->mode->value,
                'authorized_user_ids' => $setting->currentAuthorizations->pluck('user_id')->sort()->values()->all(),
            ];

            $userIds = collect($authorizedUserIds)->map(fn ($id) => (int) $id)->unique()->values();
            if ($mode === OfficeDocumentAccessMode::ManagerAssignment) {
                $userIds = collect();
            }

            $validUserCount = User::query()
                ->whereIn('id', $userIds)
                ->where('status', 'active')
                ->whereHas('currentOfficeMemberships', fn ($membership) => $membership
                    ->where('office_id', $targetOffice->id)
                    ->where('membership_role', OfficeMembershipRole::Official->value))
                ->count();

            if ($validUserCount !== $userIds->count()) {
                throw ValidationException::withMessages([
                    'authorized_user_ids' => 'El equipo solo puede incluir funcionarios activos y vigentes de esta oficina.',
                ]);
            }

            $now = now();
            $current = $setting->currentAuthorizations()->lockForUpdate()->get();
            $current->whereNotIn('user_id', $userIds)->each->update(['effective_to' => $now]);
            $existingIds = $current->pluck('user_id')->map(fn ($id) => (int) $id);

            foreach ($userIds->diff($existingIds) as $userId) {
                OfficeDocumentAccessAuthorization::query()->create([
                    'office_document_access_setting_id' => $setting->id,
                    'user_id' => $userId,
                    'authorized_by' => $actor->id,
                    'effective_from' => $now,
                ]);
            }

            $setting->update(['mode' => $mode, 'configured_by' => $actor->id]);
            $setting->load(['office', 'currentAuthorizations.user']);

            $this->activityLogger->record(
                event: 'document_management.office_access_setting.updated',
                actor: $actor,
                subject: $setting,
                context: $context,
                oldValues: $oldValues,
                newValues: [
                    'mode' => $setting->mode->value,
                    'authorized_user_ids' => $setting->currentAuthorizations->pluck('user_id')->sort()->values()->all(),
                ],
            );

            return $setting;
        });
    }

    private function hasResponsibleAssignment(User $user, Expedient $expedient, int $officeId): bool
    {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return false;
        }

        return ExpedientInternalAssignment::query()
            ->where('user_id', $user->id)
            ->where('assignment_role', 'responsible')
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('recipient', fn ($recipient) => $recipient
                ->where('expedient_movement_id', $latestMovementId)
                ->where('recipient_office_id', $officeId))
            ->exists();
    }

    private function hasAnyResponsibleAssignment(Expedient $expedient, int $officeId): bool
    {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return false;
        }

        return ExpedientInternalAssignment::query()
            ->where('assignment_role', 'responsible')
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('recipient', fn ($recipient) => $recipient
                ->where('expedient_movement_id', $latestMovementId)
                ->where('recipient_office_id', $officeId))
            ->exists();
    }
}
