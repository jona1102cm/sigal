<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\UpdateMovementRecipientStatusData;
use App\Domain\DocumentManagement\Services\ExpedientMovementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\StoreExpedientMovementRequest;
use App\Http\Requests\DocumentManagement\UpdateMovementRecipientStatusRequest;
use App\Http\Resources\DocumentManagement\ExpedientMovementRecipientResource;
use App\Http\Resources\DocumentManagement\ExpedientMovementResource;
use App\Models\Expedient;
use App\Models\ExpedientMovementRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Expone derivaciones y acciones independientes de sus oficinas destinatarias. */
class ExpedientMovementController extends Controller
{
    public function __construct(private readonly ExpedientMovementService $expedientMovementService) {}

    public function index(Request $request, Expedient $expedient): AnonymousResourceCollection
    {
        $this->authorize('view', $expedient);

        return ExpedientMovementResource::collection(
            $expedient->movements()
                ->with(['senderOffice', 'sentBy', 'recipients.recipientOffice', 'recipients.receivedBy', 'recipients.completedBy', 'documents'])
                ->latest('sent_at')
                ->latest('id')
                ->paginate(),
        );
    }

    public function store(StoreExpedientMovementRequest $request, Expedient $expedient): JsonResponse
    {
        $movement = $this->expedientMovementService->create(
            $expedient,
            CreateExpedientMovementData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new ExpedientMovementResource($movement))
            ->response()
            ->setStatusCode(201);
    }

    public function updateRecipientStatus(
        UpdateMovementRecipientStatusRequest $request,
        Expedient $expedient,
        ExpedientMovementRecipient $recipient,
    ): ExpedientMovementRecipientResource {
        $recipient = $this->expedientMovementService->updateRecipientStatus(
            $expedient,
            $recipient,
            UpdateMovementRecipientStatusData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new ExpedientMovementRecipientResource($recipient);
    }
}
