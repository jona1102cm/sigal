<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateDocumentData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\DocumentContentData;
use App\Domain\DocumentManagement\Services\DocumentService;
use App\Domain\DocumentManagement\Services\DocumentWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\AttachDocumentFileRequest;
use App\Http\Requests\DocumentManagement\LinkDocumentToMovementRequest;
use App\Http\Requests\DocumentManagement\StoreDocumentRequest;
use App\Http\Requests\DocumentManagement\UpdateDocumentRequest;
use App\Http\Resources\DocumentManagement\DocumentAttachmentResource;
use App\Http\Resources\DocumentManagement\DocumentResource;
use App\Http\Resources\DocumentManagement\DocumentRevisionResource;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Expedient;
use App\Models\ExpedientMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    private const RELATIONS = [
        'documentType',
        'issuingOffice',
        'numberSeries',
        'createdBy',
        'issuedBy',
        'attachments',
        'movements.senderOffice',
        'movements.recipients.recipientOffice',
    ];

    public function __construct(
        private readonly DocumentService $documentService,
        private readonly DocumentWorkflowService $documentWorkflowService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request, Expedient $expedient): AnonymousResourceCollection
    {
        $this->authorize('view', $expedient);

        return DocumentResource::collection(
            $expedient->documents()->with(self::RELATIONS)->latest('id')->paginate(),
        );
    }

    public function store(StoreDocumentRequest $request, Expedient $expedient): JsonResponse
    {
        $validated = $request->validated();
        $documentData = CreateDocumentData::fromValidated($validated);
        $document = $this->documentWorkflowService->createDraft(
            $expedient,
            $documentData,
            CreateExpedientMovementData::fromOptionalDerivation($validated, $documentData->issuingOfficeId),
            $request->file('attachments', []),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new DocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Expedient $expedient, Document $document): DocumentResource
    {
        $this->authorize('view', $expedient);

        abort_unless($document->expedient_id === $expedient->id, 404);

        return new DocumentResource($document->load(self::RELATIONS));
    }

    public function update(UpdateDocumentRequest $request, Expedient $expedient, Document $document): DocumentResource
    {
        return new DocumentResource($this->documentService->updateDraft(
            $expedient,
            $document,
            DocumentContentData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function issue(Request $request, Expedient $expedient, Document $document): DocumentResource
    {
        $this->authorize('manageDocuments', $expedient);

        return new DocumentResource($this->documentService->issue(
            $expedient,
            $document,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function createCorrection(UpdateDocumentRequest $request, Expedient $expedient, Document $document): JsonResponse
    {
        $correction = $this->documentService->createCorrection(
            $expedient,
            $document,
            DocumentContentData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new DocumentResource($correction))
            ->response()
            ->setStatusCode(201);
    }

    public function attach(
        AttachDocumentFileRequest $request,
        Expedient $expedient,
        Document $document,
    ): JsonResponse {
        $attachment = $this->documentService->attachFile(
            $expedient,
            $document,
            $request->file('file'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new DocumentAttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    public function revisions(Request $request, Expedient $expedient, Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $expedient);

        abort_unless($document->expedient_id === $expedient->id, 404);

        return DocumentRevisionResource::collection(
            $document->revisions()->latest('revision_number')->paginate(),
        );
    }

    public function downloadAttachment(
        Request $request,
        Expedient $expedient,
        Document $document,
        DocumentAttachment $attachment,
    ): StreamedResponse {
        $this->authorize('view', $expedient);
        abort_unless($document->expedient_id === $expedient->id && $attachment->document_id === $document->id, 404);

        $this->activityLogger->record(
            event: 'document_management.document_attachment.downloaded',
            actor: $request->user(),
            subject: $attachment,
            context: RequestAuditContext::fromRequest($request),
            newValues: [
                'document_id' => $document->id,
                'original_name' => $attachment->original_name,
                'content_hash' => $attachment->content_hash,
            ],
        );

        return Storage::disk($attachment->storage_disk)->download($attachment->storage_path, $attachment->original_name);
    }

    public function linkToMovement(
        LinkDocumentToMovementRequest $request,
        Expedient $expedient,
        Document $document,
    ): JsonResponse {
        $movement = ExpedientMovement::query()->findOrFail($request->validated('movement_id'));

        $this->documentService->linkToMovement(
            $expedient,
            $movement,
            $document,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(status: 204);
    }
}
