<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\InitialDocumentData;
use App\Domain\DocumentManagement\Services\DocumentedExpedientEntryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\StoreDocumentedExpedientRequest;
use App\Http\Resources\DocumentManagement\ExpedientResource;
use Illuminate\Http\JsonResponse;

class DocumentedExpedientEntryController extends Controller
{
    public function __construct(private readonly DocumentedExpedientEntryService $entryService) {}

    public function store(StoreDocumentedExpedientRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $expedientData = CreateExpedientData::fromValidated($validated);
        $expedient = $this->entryService->register(
            $expedientData,
            InitialDocumentData::fromValidated($validated),
            $request->file('attachments', []),
            CreateExpedientMovementData::fromOptionalDerivation($validated, $expedientData->responsibleOfficeId),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new ExpedientResource($expedient))
            ->response()
            ->setStatusCode(201);
    }
}
