<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\CreateDocumentData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Models\Document;
use App\Models\Expedient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        // Se conservan los modelos escritos para poder retirar sus binarios si la BD revierte.
        $storedAttachments = [];

        try {
            return DB::transaction(function () use ($expedient, $documentData, $derivationData, $attachments, $actor, $context, &$storedAttachments): Document {
                $document = $this->documentService->createDraft($expedient, $documentData, $actor, $context);

                foreach ($attachments as $attachment) {
                    $storedAttachments[] = $this->documentService->attachFile($expedient, $document, $attachment, $actor, $context);
                }

                if ($derivationData !== null) {
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
}
