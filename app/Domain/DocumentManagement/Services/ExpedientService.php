<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\Enums\ExpedientOrigin;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\SenderType;
use App\Models\ConfidentialityLevel;
use App\Models\Expedient;
use App\Models\ExpedientAccessGrant;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpedientService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NumberSequenceService $numberSequenceService,
    ) {}

    public function create(CreateExpedientData $data, User $actor, RequestAuditContext $context): Expedient
    {
        return DB::transaction(function () use ($data, $actor, $context): Expedient {
            $legislature = Legislature::query()->active()->lockForUpdate()->first();

            if ($legislature === null) {
                throw ValidationException::withMessages([
                    'legislature' => 'Debe existir una legislatura activa para registrar un expediente.',
                ]);
            }

            $expedientType = ExpedientType::query()->active()->findOrFail($data->expedientTypeId);
            $responsibleOffice = Office::query()->active()->supportingStaffing()->lockForUpdate()->findOrFail($data->responsibleOfficeId);
            $confidentialityLevel = $this->resolveConfidentialityLevel($data->confidentialityLevelId);

            if (! $actor->isSuperAdministrator() && ! $actor->currentOfficeMemberships()
                ->where('office_id', $responsibleOffice->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'responsible_office_id' => 'El usuario debe pertenecer a la oficina responsable del expediente.',
                ]);
            }

            [$originOffice, $senderType, $senderName] = match ($data->origin) {
                ExpedientOrigin::Internal => [$responsibleOffice, SenderType::Organization, $responsibleOffice->name],
                ExpedientOrigin::External => [null, $data->senderType, $data->senderName],
            };

            $route = $this->numberSequenceService->reserveInstitutionalRouteNumber($legislature);

            $expedient = Expedient::query()->create([
                'legislature_id' => $legislature->id,
                'expedient_type_id' => $expedientType->id,
                'confidentiality_level_id' => $confidentialityLevel->id,
                'route_number' => $route->number,
                'route_code' => $route->formatted,
                'subject' => $data->subject,
                'summary' => $data->summary,
                'origin' => $data->origin,
                'sender_type' => $senderType,
                'sender_name' => $senderName,
                'origin_office_id' => $originOffice?->id,
                'responsible_office_id' => $responsibleOffice->id,
                'received_on' => $data->receivedOn,
                'priority' => $data->priority,
                'due_on' => $data->dueOn,
                'classification' => $data->classification,
                'observations' => $data->observations,
                'status' => ExpedientStatus::Registered,
                'created_by' => $actor->id,
            ]);

            if ($confidentialityLevel->requires_explicit_access) {
                $this->grantInitialConfidentialAccess($expedient, $actor, $responsibleOffice);
            }

            $this->activityLogger->record(
                event: 'document_management.expedient.created',
                actor: $actor,
                subject: $expedient,
                context: $context,
                newValues: $this->snapshot($expedient),
            );

            return $expedient->load([
                'legislature',
                'expedientType',
                'confidentialityLevel',
                'originOffice',
                'responsibleOffice',
                'createdBy',
            ]);
        });
    }

    private function resolveConfidentialityLevel(?int $confidentialityLevelId): ConfidentialityLevel
    {
        if ($confidentialityLevelId !== null) {
            return ConfidentialityLevel::query()->active()->findOrFail($confidentialityLevelId);
        }

        return ConfidentialityLevel::query()
            ->active()
            ->where('code', 'INTERNAL')
            ->firstOrFail();
    }

    private function grantInitialConfidentialAccess(Expedient $expedient, User $actor, Office $responsibleOffice): void
    {
        $now = now();

        ExpedientAccessGrant::query()->create([
            'expedient_id' => $expedient->id,
            'user_id' => $actor->id,
            'granted_by' => $actor->id,
            'effective_from' => $now,
            'reason' => 'Acceso inicial por registro del expediente.',
        ]);

        ExpedientAccessGrant::query()->create([
            'expedient_id' => $expedient->id,
            'office_id' => $responsibleOffice->id,
            'granted_by' => $actor->id,
            'effective_from' => $now,
            'reason' => 'Acceso inicial de la oficina responsable.',
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Expedient $expedient): array
    {
        return [
            'id' => $expedient->id,
            'legislature_id' => $expedient->legislature_id,
            'expedient_type_id' => $expedient->expedient_type_id,
            'confidentiality_level_id' => $expedient->confidentiality_level_id,
            'route_number' => $expedient->route_number,
            'route_code' => $expedient->route_code,
            'subject' => $expedient->subject,
            'origin' => $expedient->origin->value,
            'responsible_office_id' => $expedient->responsible_office_id,
            'status' => $expedient->status->value,
        ];
    }
}
