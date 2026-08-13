<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\GrantExpedientAccessData;
use App\Domain\DocumentManagement\Services\ExpedientAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\GrantExpedientAccessRequest;
use App\Http\Resources\DocumentManagement\ExpedientAccessGrantResource;
use App\Models\Expedient;
use App\Models\ExpedientAccessGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpedientAccessController extends Controller
{
    public function __construct(private readonly ExpedientAccessService $expedientAccessService) {}

    public function index(Request $request, Expedient $expedient): AnonymousResourceCollection
    {
        $this->authorize('manageAccess', $expedient);

        return ExpedientAccessGrantResource::collection(
            $expedient->accessGrants()->with(['user', 'office'])->latest('effective_from')->paginate(),
        );
    }

    public function store(GrantExpedientAccessRequest $request, Expedient $expedient): JsonResponse
    {
        $grant = $this->expedientAccessService->grant(
            $expedient,
            GrantExpedientAccessData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new ExpedientAccessGrantResource($grant))
            ->response()
            ->setStatusCode(201);
    }

    public function close(Request $request, Expedient $expedient, ExpedientAccessGrant $grant): ExpedientAccessGrantResource
    {
        $this->authorize('manageAccess', $expedient);

        $grant = $this->expedientAccessService->close(
            $expedient,
            $grant,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new ExpedientAccessGrantResource($grant);
    }
}
