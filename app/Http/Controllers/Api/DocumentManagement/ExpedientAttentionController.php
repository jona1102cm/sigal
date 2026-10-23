<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\Services\ExpedientAttentionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\ClaimExpedientAttentionRequest;
use App\Http\Requests\DocumentManagement\DecideExpedientAssignmentRequest;
use App\Http\Requests\DocumentManagement\RequestExpedientAssignmentRequest;
use App\Http\Requests\DocumentManagement\TransferExpedientAttentionRequest;
use App\Models\Expedient;
use App\Models\ExpedientAssignmentRequest;
use App\Models\ExpedientMovementRecipient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Expone lectura individual y transferencia voluntaria del control operativo. */
class ExpedientAttentionController extends Controller
{
    public function __construct(private readonly ExpedientAttentionService $attentionService) {}

    public function show(Request $request, Expedient $expedient): JsonResponse
    {
        $this->authorize('view', $expedient);

        return response()->json([
            'data' => $this->attentionService->attentionPayload(
                $expedient,
                $request->user(),
                RequestAuditContext::fromRequest($request),
            ),
        ]);
    }

    public function markRead(Request $request, Expedient $expedient): JsonResponse
    {
        $this->authorize('view', $expedient);
        $count = $this->attentionService->markCurrentArrivalRead(
            $expedient,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => ['new_reads' => $count]]);
    }

    public function claim(ClaimExpedientAttentionRequest $request, Expedient $expedient, ExpedientMovementRecipient $recipient): JsonResponse
    {
        $this->attentionService->claim($expedient, $recipient, $request->user(), RequestAuditContext::fromRequest($request));

        return $this->state($request, $expedient);
    }

    public function requestAssignment(RequestExpedientAssignmentRequest $request, Expedient $expedient, ExpedientMovementRecipient $recipient): JsonResponse
    {
        $this->attentionService->requestAssignment(
            $expedient,
            $recipient,
            $request->validated('justification'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return $this->state($request, $expedient, 201);
    }

    public function decide(
        DecideExpedientAssignmentRequest $request,
        Expedient $expedient,
        ExpedientMovementRecipient $recipient,
        ExpedientAssignmentRequest $assignmentRequest,
    ): JsonResponse {
        $this->attentionService->decideRequest(
            $expedient,
            $recipient,
            $assignmentRequest,
            $request->validated('decision') === 'accept',
            $request->validated('decision_note'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return $this->state($request, $expedient);
    }

    public function transfer(TransferExpedientAttentionRequest $request, Expedient $expedient, ExpedientMovementRecipient $recipient): JsonResponse
    {
        $newResponsible = User::query()->findOrFail((int) $request->validated('new_responsible_user_id'));
        $this->attentionService->transfer(
            $expedient,
            $recipient,
            $newResponsible,
            $request->validated('note'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return $this->state($request, $expedient);
    }

    private function state(Request $request, Expedient $expedient, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $this->attentionService->attentionPayload(
                $expedient->fresh(),
                $request->user(),
                RequestAuditContext::fromRequest($request),
            ),
        ], $status);
    }
}
