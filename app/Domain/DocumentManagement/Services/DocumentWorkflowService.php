<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\CreateDocumentData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\Legislatures\Services\LegislativeAuthorizationService;
use App\Models\Document;
use App\Models\Expedient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Coordina un documento posterior, sus archivos y la derivación que lo transporta.
 *
 * Complementa la transacción de base con una compensación manual: si algo falla,
 * elimina del storage los binarios que ya se hubieran escrito.
 */
class DocumentWorkflowService
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly ExpedientMovementService $expedientMovementService,
        private readonly LegislativeAuthorizationService $legislativeAuthorization,
    ) {}

    /** @param list<UploadedFile> $attachments */
    public function createDraft(
        Expedient $expedient,
        CreateDocumentData $documentData,
        ?CreateExpedientMovementData $derivationData,
        array $attachments,
        User $actor,
        RequestAuditContext $context,
    ): Document {
        if ($derivationData === null) {
            $issuingOfficeIsLegislative = $this->legislativeAuthorization->isLegislativeOffice($documentData->issuingOfficeId);
            $isAdministrativePreparer = $actor->currentOfficeMemberships()
                ->where('office_id', $documentData->issuingOfficeId)
                ->exists();
            $actsAsAuthority = $this->legislativeAuthorization->canActForOffice(
                $actor,
                $documentData->issuingOfficeId,
                $context->actingBodyMembershipId,
            );
            if (! $issuingOfficeIsLegislative || ! $isAdministrativePreparer || $actsAsAuthority) {
                throw ValidationException::withMessages([
                    'primary_office_ids' => 'Seleccione al menos una oficina destinataria. Solo el personal administrativo de una bancada o comisión puede guardar un borrador sin derivarlo.',
                ]);
            }
        }

        // Se conservan los modelos escritos para poder retirar sus binarios si la BD revierte.
        $storedAttachments = [];

        try {
            return DB::transaction(function () use ($expedient, $documentData, $derivationData, $attachments, $actor, $context, &$storedAttachments): Document {
                $document = $this->documentService->createDraft($expedient, $documentData, $actor, $context);

                foreach ($attachments as $attachment) {
                    $storedAttachments[] = $this->documentService->attachFile($expedient, $document, $attachment, $actor, $context);
                }

                if ($derivationData !== null) {
                    // Una salida legislativa debe quedar firmada por la autoridad
                    // antes de que Secretaría General reciba la documentación.
                    if ($this->legislativeAuthorization->isLegislativeOffice($documentData->issuingOfficeId)) {
                        $document = $this->documentService->issue($expedient, $document, $actor, $context);
                    }
                    $movement = $this->expedientMovementService->create($expedient, $derivationData, $actor, $context);
                    $this->documentService->linkToMovement($expedient, $movement, $document, $actor, $context);
                }

                return $document->refresh()->load(['documentType', 'issuingOffice', 'numberSeries', 'createdBy', 'issuedBy', 'attachments']);
            });
        } catch (Throwable $exception) {
            // Una transacción PostgreSQL no puede revertir por sí sola escrituras al filesystem.
            foreach ($storedAttachments as $attachment) {
                Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
            }

            throw $exception;
        }
    }

    public function issueAndDerive(
        Expedient $expedient,
        Document $document,
        CreateExpedientMovementData $derivationData,
        User $actor,
        RequestAuditContext $context,
    ): Document {
        return DB::transaction(function () use ($expedient, $document, $derivationData, $actor, $context): Document {
            $issued = $this->documentService->issue($expedient, $document, $actor, $context);
            $movement = $this->expedientMovementService->create($expedient, $derivationData, $actor, $context);
            $this->documentService->linkToMovement($expedient, $movement, $issued, $actor, $context);

            return $issued->refresh()->load(['documentType', 'issuingOffice', 'numberSeries', 'createdBy', 'issuedBy', 'attachments']);
        });
    }
}
