<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\Services\ExpedientInternalAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\UpdateExpedientInternalAssignmentsRequest;
use App\Models\Expedient;
use App\Models\ExpedientMovementRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Permite a la jefatura distribuir un ingreso dentro de su oficina. */
class ExpedientInternalAssignmentController extends Controller
{
    public function __construct(private readonly ExpedientInternalAssignmentService $assignmentService) {}

    public function show(Request $request, Expedient $expedient, ExpedientMovementRecipient $recipient): JsonResponse
    {
        $this->authorize('manageInternalAssignments', $expedient);
        $recipient = $this->recipient($expedient, $recipient);
        abort_unless(
            $request->user()->isSuperAdministrator()
                || $request->user()->isCurrentManagerOfOffice($recipient->recipient_office_id),
            403,
        );

        return response()->json(['data' => $this->payload($recipient)]);
    }

    public function update(
        UpdateExpedientInternalAssignmentsRequest $request,
        Expedient $expedient,
        ExpedientMovementRecipient $recipient,
    ): JsonResponse {
        $recipient = $this->assignmentService->replace(
            $expedient,
            $recipient,
            (int) $request->validated('responsible_user_id'),
            $request->validated('collaborator_user_ids'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($recipient)]);
    }

    private function recipient(Expedient $expedient, ExpedientMovementRecipient $recipient): ExpedientMovementRecipient
    {
        return ExpedientMovementRecipient::query()
            ->whereHas('movement', fn ($movement) => $movement->where('expedient_id', $expedient->id))
            ->with(['recipientOffice', 'currentInternalAssignments.user'])
            ->findOrFail($recipient->id);
    }

    /** @return array<string, mixed> */
    private function payload(ExpedientMovementRecipient $recipient): array
    {
        $recipient->loadMissing(['recipientOffice', 'currentInternalAssignments.user']);
        $members = $recipient->recipientOffice->currentMemberships()
            ->with('user')
            ->whereHas('user', fn ($user) => $user->where('status', 'active'))
            ->get();

        return [
            'recipient_id' => $recipient->id,
            'office' => [
                'id' => $recipient->recipientOffice->id,
                'code' => $recipient->recipientOffice->code,
                'name' => $recipient->recipientOffice->name,
            ],
            'members' => $members->map(fn ($membership) => [
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'position_title' => $membership->position_title,
                'membership_role' => $membership->membership_role->value,
            ])->values(),
            'assignments' => $recipient->currentInternalAssignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'name' => $assignment->user->name,
                'assignment_role' => $assignment->assignment_role->value,
                'assignment_role_label' => $assignment->assignment_role->label(),
                'effective_from' => $assignment->effective_from->toIso8601String(),
            ])->values(),
        ];
    }
}
