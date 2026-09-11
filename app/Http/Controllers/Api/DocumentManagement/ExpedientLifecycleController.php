<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\LifecycleActionData;
use App\Domain\DocumentManagement\DTOs\ReopeningDecisionData;
use App\Domain\DocumentManagement\Services\ExpedientLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\DecideExpedientReopeningRequest;
use App\Http\Requests\DocumentManagement\LifecycleActionRequest;
use App\Http\Requests\DocumentManagement\RequestExpedientReopeningRequest;
use App\Http\Resources\DocumentManagement\ExpedientReopeningRequestResource;
use App\Http\Resources\DocumentManagement\ExpedientResource;
use App\Models\Expedient;
use App\Models\ExpedientReopeningRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Expone archivo, cierre, anulación y el procedimiento auditable de reapertura. */
class ExpedientLifecycleController extends Controller
{
    public function __construct(private readonly ExpedientLifecycleService $expedientLifecycleService) {}

    public function archive(LifecycleActionRequest $request, Expedient $expedient): ExpedientResource
    {
        return new ExpedientResource($this->expedientLifecycleService->archive(
            $expedient,
            LifecycleActionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function close(LifecycleActionRequest $request, Expedient $expedient): ExpedientResource
    {
        return new ExpedientResource($this->expedientLifecycleService->close(
            $expedient,
            LifecycleActionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function void(LifecycleActionRequest $request, Expedient $expedient): ExpedientResource
    {
        return new ExpedientResource($this->expedientLifecycleService->void(
            $expedient,
            LifecycleActionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function requestReopening(RequestExpedientReopeningRequest $request, Expedient $expedient): ExpedientReopeningRequestResource
    {
        return new ExpedientReopeningRequestResource($this->expedientLifecycleService->requestReopening(
            $expedient,
            LifecycleActionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function reopeningRequests(Request $request, Expedient $expedient): AnonymousResourceCollection
    {
        $this->authorize('viewReopeningHistory', $expedient);

        return ExpedientReopeningRequestResource::collection(
            $expedient->reopeningRequests()->latest('created_at')->paginate(),
        );
    }

    public function approveReopening(
        DecideExpedientReopeningRequest $request,
        Expedient $expedient,
        ExpedientReopeningRequest $reopeningRequest,
    ): ExpedientReopeningRequestResource {
        return new ExpedientReopeningRequestResource($this->expedientLifecycleService->approveReopening(
            $expedient,
            $reopeningRequest,
            ReopeningDecisionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function rejectReopening(
        DecideExpedientReopeningRequest $request,
        Expedient $expedient,
        ExpedientReopeningRequest $reopeningRequest,
    ): ExpedientReopeningRequestResource {
        return new ExpedientReopeningRequestResource($this->expedientLifecycleService->rejectReopening(
            $expedient,
            $reopeningRequest,
            ReopeningDecisionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }
}
