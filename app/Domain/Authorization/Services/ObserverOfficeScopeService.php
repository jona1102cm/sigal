<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Services\OfficeHierarchyService;
use App\Models\ObserverOfficeScope;
use App\Models\Office;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Mantiene las oficinas raíz que cada Observador puede consultar. */
class ObserverOfficeScopeService
{
    public function __construct(
        private readonly OfficeHierarchyService $officeHierarchy,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @return Collection<int, int> */
    public function effectiveOfficeIds(User $user): Collection
    {
        $assignment = $this->activeAssignment($user);
        if ($assignment === null) {
            return collect();
        }

        $rootIds = $assignment->currentObserverOfficeScopes()->pluck('office_id');

        return $this->officeHierarchy->expandWithDescendants($rootIds);
    }

    /**
     * Devuelve los ámbitos que alguna vez estuvieron vigentes en la asignación
     * activa de Observador. Un ámbito cerrado conserva los expedientes que ya
     * habían participado en esas oficinas, pero no alcanza actuaciones futuras.
     *
     * @return Collection<int, array{office_ids: Collection<int, int>, visible_until: mixed}>
     */
    public function visibilityWindows(User $user): Collection
    {
        $assignment = $this->activeAssignment($user);
        if ($assignment === null) {
            return collect();
        }

        return $assignment->observerOfficeScopes()
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->get()
            ->map(fn (ObserverOfficeScope $scope) => [
                'office_ids' => $this->officeHierarchy->expandWithDescendants([$scope->office_id]),
                'visible_until' => $scope->effective_to,
            ]);
    }

    /** @param list<int> $officeIds */
    public function sync(User $user, array $officeIds, User $actor, RequestAuditContext $context): UserRoleAssignment
    {
        return DB::transaction(function () use ($user, $officeIds, $actor, $context): UserRoleAssignment {
            $assignment = $this->activeAssignment($user, lock: true);
            if ($assignment === null) {
                throw ValidationException::withMessages([
                    'role' => 'Primero debe asignar al usuario el rol Observador.',
                ]);
            }

            $officeIds = collect($officeIds)->map(fn ($id) => (int) $id)->unique()->values();
            if (Office::query()->active()->whereIn('id', $officeIds)->count() !== $officeIds->count()) {
                throw ValidationException::withMessages([
                    'office_ids' => 'Todas las oficinas seleccionadas deben existir y estar activas.',
                ]);
            }

            $current = $assignment->currentObserverOfficeScopes()->lockForUpdate()->get();
            $oldRootIds = $current->pluck('office_id')->map(fn ($id) => (int) $id)->sort()->values();
            $now = now();

            $current->whereNotIn('office_id', $officeIds)->each->update(['effective_to' => $now]);
            $existingIds = $current->pluck('office_id')->map(fn ($id) => (int) $id);

            foreach ($officeIds->diff($existingIds) as $officeId) {
                ObserverOfficeScope::query()->create([
                    'user_role_assignment_id' => $assignment->id,
                    'office_id' => $officeId,
                    'assigned_by' => $actor->id,
                    'effective_from' => $now,
                ]);
            }

            $this->activityLogger->record(
                event: 'authorization.observer_office_scope.updated',
                actor: $actor,
                subject: $assignment,
                context: $context,
                oldValues: ['root_office_ids' => $oldRootIds->all()],
                newValues: [
                    'root_office_ids' => $officeIds->sort()->values()->all(),
                    'effective_office_ids' => $this->officeHierarchy->expandWithDescendants($officeIds)->all(),
                ],
            );

            return $assignment->fresh(['currentObserverOfficeScopes.office']);
        });
    }

    private function activeAssignment(User $user, bool $lock = false): ?UserRoleAssignment
    {
        $query = $user->currentRoleAssignments()
            ->whereHas('role', fn ($role) => $role->where('code', RoleCode::Observer->value));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
