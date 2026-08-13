<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\InitialDocumentData;
use App\Models\Expedient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso normal de ingreso: expediente, documento inicial y derivación opcional.
 *
 * La transacción garantiza que el proceso administrativo no quede separado de la
 * documentación que justificó su apertura.
 */
class DocumentedExpedientEntryService
{
    public function __construct(
        private readonly ExpedientService $expedientService,
        private readonly DocumentService $documentService,
        private readonly ExpedientMovementService $expedientMovementService,
    ) {}

    /** @param list<UploadedFile> $attachments */
    public function register(
        CreateExpedientData $expedientData,
        InitialDocumentData $documentData,
        array $attachments,
        ?CreateExpedientMovementData $derivationData,
        User $actor,
        RequestAuditContext $context,
    ): Expedient {
        return DB::transaction(function () use ($expedientData, $documentData, $attachments, $derivationData, $actor, $context): Expedient {
            // El orden refleja la dependencia: el documento necesita un expediente existente.
            $expedient = $this->expedientService->create($expedientData, $actor, $context);

            $document = $this->documentService->registerInitialDocument(
                $expedient,
                $documentData,
                $attachments,
                $actor,
                $context,
            );

            // Se conserva null para el alta excepcional autorizada sin derivación inmediata.
            if ($derivationData !== null) {
                $movement = $this->expedientMovementService->create($expedient, $derivationData, $actor, $context);
                $this->documentService->linkToMovement($expedient, $movement, $document, $actor, $context);
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
