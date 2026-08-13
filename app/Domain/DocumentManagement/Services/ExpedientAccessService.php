<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\GrantExpedientAccessData;
use App\Models\Expedient;
use App\Models\ExpedientAccessGrant;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administra concesiones extraordinarias de lectura sobre un expediente.
 *
 * Cada concesión se cierra por vigencia y conserva quién la otorgó; no reemplaza
 * las reglas ordinarias de acceso por rol, creación, oficina o jerarquía.
 */
class ExpedientAccessService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function grant(
        Expedient $expedient,
        GrantExpedientAccessData $data,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientAccessGrant {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): ExpedientAccessGrant {
            if (($data->userId === null) === ($data->officeId === null)) {
                throw ValidationException::withMessages([
                    'access_target' => 'Debe seleccionar exactamente un usuario o una oficina para la autorización.',
                ]);
            }

            if ($data->userId !== null) {
                User::query()->findOrFail($data->userId);
            }

            if ($data->officeId !== null) {
                Office::query()->active()->supportingStaffing()->findOrFail($data->officeId);
            }

            $currentGrant = ExpedientAccessGrant::query()
                ->where('expedient_id', $expedient->id)
                ->when($data->userId !== null, fn ($query) => $query->where('user_id', $data->userId))
                ->when($data->officeId !== null, fn ($query) => $query->where('office_id', $data->officeId))
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($currentGrant !== null) {
                throw ValidationException::withMessages([
                    'access_target' => 'El destinatario ya cuenta con una autorización vigente.',
                ]);
            }

            $grant = ExpedientAccessGrant::query()->create([
                'expedient_id' => $expedient->id,
                'user_id' => $data->userId,
                'office_id' => $data->officeId,
                'granted_by' => $actor->id,
                'effective_from' => $data->effectiveFrom,
                'reason' => $data->reason,
            ]);

            $this->activityLogger->record(
                event: 'document_management.expedient_access.granted',
                actor: $actor,
                subject: $grant,
                context: $context,
                newValues: $this->snapshot($grant),
            );

            return $grant->load(['user', 'office']);
        });
    }

    public function close(
        Expedient $expedient,
        ExpedientAccessGrant $grant,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientAccessGrant {
        return DB::transaction(function () use ($expedient, $grant, $actor, $context): ExpedientAccessGrant {
            $target = ExpedientAccessGrant::query()
                ->where('expedient_id', $expedient->id)
                ->lockForUpdate()
                ->findOrFail($grant->id);

            if ($target->effective_to !== null) {
                throw ValidationException::withMessages([
                    'grant' => 'La autorización ya se encuentra cerrada.',
                ]);
            }

            $oldValues = $this->snapshot($target);
            $target->update(['effective_to' => now()]);

            $this->activityLogger->record(
                event: 'document_management.expedient_access.closed',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target->load(['user', 'office']);
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ExpedientAccessGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'expedient_id' => $grant->expedient_id,
            'user_id' => $grant->user_id,
            'office_id' => $grant->office_id,
            'effective_from' => $grant->effective_from->toIso8601String(),
            'effective_to' => $grant->effective_to?->toIso8601String(),
            'reason' => $grant->reason,
        ];
    }
}
