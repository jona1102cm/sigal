<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Models\Expedient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Conserva la apertura excepcional de un expediente sin documento inicial.
 *
 * La operación normal debe usar DocumentedExpedientEntryService; esta variante
 * existe para casos administrativos que no pueden aportar un documento al inicio.
 */
class ExpedientRegistrationService
{
    public function __construct(
        private readonly ExpedientService $expedientService,
        private readonly ExpedientMovementService $expedientMovementService,
    ) {}

    public function register(
        CreateExpedientData $expedientData,
        ?CreateExpedientMovementData $derivationData,
        User $actor,
        RequestAuditContext $context,
    ): Expedient {
        return DB::transaction(function () use ($expedientData, $derivationData, $actor, $context): Expedient {
            $expedient = $this->expedientService->create($expedientData, $actor, $context);

            if ($derivationData !== null) {
                $this->expedientMovementService->create($expedient, $derivationData, $actor, $context);
            }

            return $expedient->refresh()->load([
                'legislature',
                'expedientType',
                'confidentialityLevel',
                'originOffice',
                'responsibleOffice',
                'createdBy',
            ]);
        });
    }
}
