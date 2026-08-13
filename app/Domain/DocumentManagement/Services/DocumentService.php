<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateDocumentData;
use App\Domain\DocumentManagement\DTOs\DocumentContentData;
use App\Domain\DocumentManagement\DTOs\InitialDocumentData;
use App\Domain\DocumentManagement\Enums\DocumentStatus;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentNumberSeries;
use App\Models\DocumentRevision;
use App\Models\DocumentType;
use App\Models\Expedient;
use App\Models\ExpedientMovement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Implementa el ciclo de un documento: borrador, emisión, corrección y adjuntos.
 *
 * Una emisión queda inmutable; cualquier cambio posterior crea una revisión y
 * conserva la numeración institucional y la evidencia anterior.
 */
class DocumentService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NumberSequenceService $numberSequenceService,
        private readonly RichTextSanitizer $richTextSanitizer,
    ) {}

    public function createDraft(
        Expedient $expedient,
        CreateDocumentData $data,
        User $actor,
        RequestAuditContext $context,
    ): Document {
        return DB::transaction(function () use ($expedient, $data, $actor, $context): Document {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $this->assertExpedientAcceptsDocuments($targetExpedient);
            $documentType = DocumentType::query()->active()->findOrFail($data->documentTypeId);
            $office = Office::query()->active()->supportingStaffing()->findOrFail($data->issuingOfficeId);
            $this->assertCanManageOfficeDocuments($actor, $office, $targetExpedient);

            $content = new DocumentContentData(
                $data->content->title,
                $this->richTextSanitizer->sanitize($data->content->content),
                $data->content->officeReference,
            );
            $this->assertOfficeReferenceIsAvailable($office, $content->officeReference);
            $document = Document::query()->create([
                'expedient_id' => $targetExpedient->id,
                'document_type_id' => $documentType->id,
                'issuing_office_id' => $office->id,
                'office_reference' => $content->officeReference,
                'status' => DocumentStatus::Draft,
                'title' => $content->title,
                'content' => $content->content,
                'created_by' => $actor->id,
            ]);
            $this->recordRevision($document, $content, $actor);

            $this->activityLogger->record(
                event: 'document_management.document.drafted',
                actor: $actor,
                subject: $document,
                context: $context,
                newValues: $this->snapshot($document),
            );

            return $document->load($this->relations());
        });
    }

    /** @param list<UploadedFile> $attachments */
    public function registerInitialDocument(
        Expedient $expedient,
        InitialDocumentData $data,
        array $attachments,
        User $actor,
        RequestAuditContext $context,
    ): Document {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($expedient, $data, $attachments, $actor, $context, &$storedFiles): Document {
                $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
                $this->assertExpedientAcceptsDocuments($targetExpedient);
                $documentType = DocumentType::query()->active()->findOrFail($data->documentTypeId);
                $content = new DocumentContentData($data->title, $this->richTextSanitizer->sanitize($data->content), null);

                if ($this->hasNoMeaningfulContent($content->content) && $attachments === []) {
                    throw ValidationException::withMessages([
                        'document' => 'El ingreso debe incluir contenido redactado o al menos un archivo adjunto.',
                    ]);
                }

                $document = Document::query()->create([
                    'expedient_id' => $targetExpedient->id,
                    'document_type_id' => $documentType->id,
                    'issuing_office_id' => $targetExpedient->origin_office_id,
                    'is_initial' => true,
                    'origin_number' => $data->originNumber,
                    'origin_date' => $data->originDate,
                    'status' => DocumentStatus::Draft,
                    'title' => $content->title,
                    'content' => $content->content,
                    'created_by' => $actor->id,
                ]);
                $this->recordRevision($document, $content, $actor);

                foreach ($attachments as $attachment) {
                    $this->storeAttachment($document, $attachment, $actor, $context, $storedFiles);
                }

                $this->activityLogger->record(
                    event: 'document_management.document.initial_registered',
                    actor: $actor,
                    subject: $document,
                    context: $context,
                    newValues: $this->snapshot($document),
                );

                return $document->load($this->relations());
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($storedFiles);

            throw $exception;
        }
    }

    public function updateDraft(
        Expedient $expedient,
        Document $document,
        DocumentContentData $data,
        User $actor,
        RequestAuditContext $context,
    ): Document {
        return DB::transaction(function () use ($expedient, $document, $data, $actor, $context): Document {
            $target = $this->lockDocumentForExpedient($expedient, $document);
            $this->assertDraft($target);
            $this->assertMutableDocument($target);
            $this->assertCanManageOfficeDocuments($actor, $target->issuingOffice, $target->expedient);
            $oldValues = $this->snapshot($target);

            $content = new DocumentContentData(
                $data->title,
                $this->richTextSanitizer->sanitize($data->content),
                $data->officeReference,
            );
            $this->assertOfficeReferenceIsAvailable(
                $target->issuingOffice,
                $content->officeReference,
                $target,
            );
            $target->update([
                'title' => $content->title,
                'content' => $content->content,
                'office_reference' => $content->officeReference,
            ]);
            $this->recordRevision($target, $content, $actor);

            $this->activityLogger->record(
                event: 'document_management.document.draft_updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target->load($this->relations());
        });
    }

    public function issue(Expedient $expedient, Document $document, User $actor, RequestAuditContext $context): Document
    {
        return DB::transaction(function () use ($expedient, $document, $actor, $context): Document {
            $target = $this->lockDocumentForExpedient($expedient, $document);
            $this->assertDraft($target);
            $this->assertMutableDocument($target);
            $this->assertExpedientAcceptsDocuments($target->expedient);
            $this->assertCanManageOfficeDocuments($actor, $target->issuingOffice, $target->expedient);
            $oldValues = $this->snapshot($target);

            if ($target->documentType->is_official) {
                $this->assignOfficialNumber($target);
            } elseif ($target->version_number === 0) {
                $target->update(['version_number' => 1]);
            }

            if ($target->document_number_series_id !== null) {
                Document::query()
                    ->where('document_number_series_id', $target->document_number_series_id)
                    ->where('status', DocumentStatus::Issued->value)
                    ->update(['status' => DocumentStatus::Superseded]);
            }

            $target->update([
                'status' => DocumentStatus::Issued,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $this->activityLogger->record(
                event: 'document_management.document.issued',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target->load($this->relations());
        });
    }

    public function createCorrection(
        Expedient $expedient,
        Document $document,
        DocumentContentData $data,
        User $actor,
        RequestAuditContext $context,
    ): Document {
        return DB::transaction(function () use ($expedient, $document, $data, $actor, $context): Document {
            $target = $this->lockDocumentForExpedient($expedient, $document);

            $this->assertMutableDocument($target);

            if ($target->status !== DocumentStatus::Issued || $target->document_number_series_id === null) {
                throw ValidationException::withMessages([
                    'document' => 'Solo un documento oficial emitido puede corregirse mediante una nueva versión.',
                ]);
            }

            $this->assertCanManageOfficeDocuments($actor, $target->issuingOffice, $target->expedient);
            $series = DocumentNumberSeries::query()->lockForUpdate()->findOrFail($target->document_number_series_id);
            $series->increment('last_version_number');
            $series->refresh();

            $content = new DocumentContentData($data->title, $this->richTextSanitizer->sanitize($data->content), $target->office_reference);
            $correction = Document::query()->create([
                'expedient_id' => $target->expedient_id,
                'document_type_id' => $target->document_type_id,
                'issuing_office_id' => $target->issuing_office_id,
                'office_reference' => $target->office_reference,
                'document_number_series_id' => $series->id,
                'supersedes_document_id' => $target->id,
                'version_number' => $series->last_version_number,
                'status' => DocumentStatus::Draft,
                'title' => $content->title,
                'content' => $content->content,
                'created_by' => $actor->id,
            ]);
            $this->recordRevision($correction, $content, $actor);

            $this->activityLogger->record(
                event: 'document_management.document.correction_drafted',
                actor: $actor,
                subject: $correction,
                context: $context,
                newValues: $this->snapshot($correction),
            );

            return $correction->load($this->relations());
        });
    }

    public function attachFile(
        Expedient $expedient,
        Document $document,
        UploadedFile $file,
        User $actor,
        RequestAuditContext $context,
    ): DocumentAttachment {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($expedient, $document, $file, $actor, $context, &$storedFiles): DocumentAttachment {
                $target = $this->lockDocumentForExpedient($expedient, $document);
                $this->assertDraft($target);
                $this->assertMutableDocument($target);
                $this->assertCanManageOfficeDocuments($actor, $target->issuingOffice, $target->expedient);

                return $this->storeAttachment($target, $file, $actor, $context, $storedFiles);
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($storedFiles);

            throw $exception;
        }
    }

    public function linkToMovement(
        Expedient $expedient,
        ExpedientMovement $movement,
        Document $document,
        User $actor,
        RequestAuditContext $context,
    ): void {
        DB::transaction(function () use ($expedient, $movement, $document, $actor, $context): void {
            $targetExpedient = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $targetMovement = ExpedientMovement::query()
                ->where('expedient_id', $targetExpedient->id)
                ->lockForUpdate()
                ->findOrFail($movement->id);
            $targetDocument = Document::query()
                ->where('expedient_id', $targetExpedient->id)
                ->findOrFail($document->id);

            if ($targetMovement->sent_by !== $actor->id) {
                throw ValidationException::withMessages([
                    'movement' => 'Solo quien realizó la derivación puede vincular documentación a ella.',
                ]);
            }

            $targetMovement->documents()->syncWithoutDetaching([$targetDocument->id]);

            $this->activityLogger->record(
                event: 'document_management.expedient_movement.document_linked',
                actor: $actor,
                subject: $targetMovement,
                context: $context,
                newValues: [
                    'movement_id' => $targetMovement->id,
                    'document_id' => $targetDocument->id,
                ],
            );
        });
    }

    private function assignOfficialNumber(Document $document): void
    {
        if ($document->document_number_series_id !== null) {
            return;
        }

        $expedient = $document->expedient()->firstOrFail();
        $legislature = $expedient->legislature()->firstOrFail();
        $office = $document->issuingOffice()->firstOrFail();
        $number = $this->numberSequenceService->reserveOfficeDocumentNumber($legislature, $office);

        $series = DocumentNumberSeries::query()->create([
            'expedient_id' => $expedient->id,
            'document_type_id' => $document->document_type_id,
            'legislature_id' => $legislature->id,
            'issuing_office_id' => $office->id,
            'sequence_number' => $number->number,
            'formatted_number' => $number->formatted,
            'last_version_number' => 1,
        ]);

        $document->update([
            'document_number_series_id' => $series->id,
            'version_number' => 1,
        ]);
    }

    private function recordRevision(Document $document, DocumentContentData $data, User $actor): DocumentRevision
    {
        $nextRevision = (int) $document->revisions()->max('revision_number') + 1;

        return DocumentRevision::query()->create([
            'document_id' => $document->id,
            'revision_number' => $nextRevision,
            'title' => $data->title,
            'content' => $data->content,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{disk: string, path: string}>  $storedFiles
     */
    private function storeAttachment(
        Document $document,
        UploadedFile $file,
        User $actor,
        RequestAuditContext $context,
        array &$storedFiles,
    ): DocumentAttachment {
        $hash = hash_file('sha256', $file->getRealPath());

        if ($document->attachments()->where('content_hash', $hash)->exists()) {
            throw ValidationException::withMessages([
                'file' => 'El mismo contenido ya fue adjuntado al documento.',
            ]);
        }

        $disk = config('filesystems.default');
        $path = $file->storeAs(
            "documents/{$document->id}",
            Str::uuid()->toString().'.'.$file->extension(),
            $disk,
        );
        $storedFiles[] = ['disk' => $disk, 'path' => $path];

        $attachment = DocumentAttachment::query()->create([
            'document_id' => $document->id,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'uploaded_by' => $actor->id,
        ]);

        $this->activityLogger->record(
            event: 'document_management.document_attachment.added',
            actor: $actor,
            subject: $attachment,
            context: $context,
            newValues: [
                'document_id' => $document->id,
                'original_name' => $attachment->original_name,
                'content_hash' => $attachment->content_hash,
            ],
        );

        return $attachment;
    }

    /** @param array<int, array{disk: string, path: string}> $storedFiles */
    private function deleteStoredFiles(array $storedFiles): void
    {
        foreach ($storedFiles as $storedFile) {
            Storage::disk($storedFile['disk'])->delete($storedFile['path']);
        }
    }

    private function lockDocumentForExpedient(Expedient $expedient, Document $document): Document
    {
        return Document::query()
            ->where('expedient_id', $expedient->id)
            ->lockForUpdate()
            ->findOrFail($document->id);
    }

    private function assertExpedientAcceptsDocuments(Expedient $expedient): void
    {
        if (in_array($expedient->status, [ExpedientStatus::Archived, ExpedientStatus::Closed, ExpedientStatus::Voided], true)) {
            throw ValidationException::withMessages([
                'expedient' => 'No se pueden crear ni emitir documentos en un expediente archivado, cerrado o anulado.',
            ]);
        }
    }

    private function assertCanManageOfficeDocuments(User $actor, Office $office, Expedient $expedient): void
    {
        $this->assertActorBelongsToOffice($actor, $office);

        if ($expedient->isCurrentlyHeldByOffice($office->id)) {
            return;
        }

        throw ValidationException::withMessages([
            'office' => 'La oficina emisora debe tener actualmente el expediente para crear documentación.',
        ]);
    }

    private function assertActorBelongsToOffice(User $actor, Office $office): void
    {
        if ($actor->currentOfficeMemberships()->where('office_id', $office->id)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'office' => 'El usuario debe pertenecer a la oficina emisora del documento.',
        ]);
    }

    private function assertOfficeReferenceIsAvailable(Office $office, ?string $officeReference, ?Document $except = null): void
    {
        if ($officeReference === null) {
            return;
        }

        $duplicate = Document::query()
            ->where('issuing_office_id', $office->id)
            ->where('office_reference', $officeReference)
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except->id))
            ->when(
                $except?->document_number_series_id !== null,
                fn ($query) => $query->where(function ($documents) use ($except): void {
                    $documents->whereNull('document_number_series_id')
                        ->orWhere('document_number_series_id', '!=', $except->document_number_series_id);
                }),
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'office_reference' => 'Ese número o CITE ya está registrado para esta oficina.',
            ]);
        }
    }

    private function assertDraft(Document $document): void
    {
        if ($document->status !== DocumentStatus::Draft) {
            throw ValidationException::withMessages([
                'document' => 'Los documentos emitidos son inmutables; las correcciones generan una nueva versión.',
            ]);
        }
    }

    private function assertMutableDocument(Document $document): void
    {
        if ($document->is_initial) {
            throw ValidationException::withMessages([
                'document' => 'El documento de ingreso conserva el antecedente original y no puede modificarse.',
            ]);
        }
    }

    private function hasNoMeaningfulContent(?string $content): bool
    {
        return trim(strip_tags($content ?? '')) === '';
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['documentType', 'issuingOffice', 'numberSeries', 'createdBy', 'issuedBy', 'attachments'];
    }

    /** @return array<string, mixed> */
    private function snapshot(Document $document): array
    {
        return [
            'id' => $document->id,
            'expedient_id' => $document->expedient_id,
            'document_type_id' => $document->document_type_id,
            'issuing_office_id' => $document->issuing_office_id,
            'office_reference' => $document->office_reference,
            'is_initial' => $document->is_initial,
            'origin_number' => $document->origin_number,
            'origin_date' => $document->origin_date?->toDateString(),
            'document_number_series_id' => $document->document_number_series_id,
            'version_number' => $document->version_number,
            'status' => $document->status->value,
            'title' => $document->title,
            'issued_at' => $document->issued_at?->toIso8601String(),
        ];
    }
}
