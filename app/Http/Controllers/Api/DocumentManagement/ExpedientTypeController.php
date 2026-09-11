<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateExpedientTypeData;
use App\Domain\DocumentManagement\Services\ExpedientTypeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\StoreExpedientTypeRequest;
use App\Http\Requests\DocumentManagement\UpdateExpedientTypeRequest;
use App\Http\Resources\DocumentManagement\ConfidentialityLevelResource;
use App\Http\Resources\DocumentManagement\DocumentTypeResource;
use App\Http\Resources\DocumentManagement\ExpedientTypeResource;
use App\Models\ConfidentialityLevel;
use App\Models\DocumentType;
use App\Models\ExpedientType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Expone tipos de expediente y catálogos documentales de solo lectura. */
class ExpedientTypeController extends Controller
{
    public function __construct(
        private readonly ExpedientTypeService $expedientTypeService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExpedientType::class);

        $types = ExpedientType::query();

        if (! ($request->boolean('include_inactive') && $request->user()->isSuperAdministrator())) {
            $types->active();
        }

        return ExpedientTypeResource::collection(
            $types->orderBy('category')->orderBy('name')->paginate(),
        );
    }

    public function store(StoreExpedientTypeRequest $request): JsonResponse
    {
        $expedientType = $this->expedientTypeService->create(
            CreateExpedientTypeData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new ExpedientTypeResource($expedientType))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateExpedientTypeRequest $request, ExpedientType $expedientType): ExpedientTypeResource
    {
        $expedientType = $this->expedientTypeService->update(
            $expedientType,
            CreateExpedientTypeData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new ExpedientTypeResource($expedientType);
    }

    public function activate(Request $request, ExpedientType $expedientType): ExpedientTypeResource
    {
        $this->authorize('update', $expedientType);

        return new ExpedientTypeResource($this->expedientTypeService->activate(
            $expedientType,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function inactivate(Request $request, ExpedientType $expedientType): ExpedientTypeResource
    {
        $this->authorize('update', $expedientType);

        return new ExpedientTypeResource($this->expedientTypeService->inactivate(
            $expedientType,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function documentTypes(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExpedientType::class);

        return DocumentTypeResource::collection(DocumentType::query()->active()->orderBy('name')->paginate());
    }

    public function confidentialityLevels(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExpedientType::class);

        return ConfidentialityLevelResource::collection(
            ConfidentialityLevel::query()->active()->orderBy('sort_order')->paginate(),
        );
    }
}
