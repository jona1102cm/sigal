<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\OfficeDocumentNumberData;
use App\Domain\DocumentManagement\Services\NumberSequenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\ConfigureOfficeDocumentSequenceRequest;
use App\Http\Resources\DocumentManagement\OfficeDocumentSequenceResource;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\OfficeDocumentSequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficeDocumentSequenceController extends Controller
{
    public function __construct(private readonly NumberSequenceService $numberSequenceService) {}

    public function show(Request $request, Legislature $legislature, Office $office): OfficeDocumentSequenceResource|JsonResponse
    {
        $this->authorize('view', $legislature);

        $sequence = OfficeDocumentSequence::query()
            ->where('legislature_id', $legislature->id)
            ->where('office_id', $office->id)
            ->first();

        if ($sequence === null) {
            return response()->json(['data' => null]);
        }

        return new OfficeDocumentSequenceResource($sequence);
    }

    public function store(
        ConfigureOfficeDocumentSequenceRequest $request,
        Legislature $legislature,
        Office $office,
    ): JsonResponse {
        $sequence = $this->numberSequenceService->configureOfficeDocumentSequence(
            $legislature,
            $office,
            OfficeDocumentNumberData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new OfficeDocumentSequenceResource($sequence))
            ->response()
            ->setStatusCode(201);
    }
}
