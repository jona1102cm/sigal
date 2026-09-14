<?php

namespace App\Http\Controllers\Api\DocumentManagement;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\DocumentManagement\Enums\OfficeDocumentAccessMode;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentManagement\UpdateOfficeDocumentAccessSettingRequest;
use App\Models\Office;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Expone la preferencia permanente de reparto documental de cada oficina. */
class OfficeDocumentAccessController extends Controller
{
    public function __construct(private readonly OfficeDocumentAccessService $accessService) {}

    public function show(Request $request, Office $office): JsonResponse
    {
        $this->authorize('configureDocumentAccess', $office);

        return response()->json(['data' => $this->payload($office)]);
    }

    public function update(UpdateOfficeDocumentAccessSettingRequest $request, Office $office): JsonResponse
    {
        $this->accessService->updateSetting(
            $office,
            OfficeDocumentAccessMode::from($request->validated('mode')),
            $request->validated('authorized_user_ids'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $this->payload($office->fresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(Office $office): array
    {
        $office->load(['documentAccessSetting.currentAuthorizations.user']);
        $memberships = $office->currentMemberships()
            ->with('user')
            ->whereHas('user', fn ($user) => $user->where('status', 'active'))
            ->orderBy('position_title')
            ->get();
        $mode = $this->accessService->modeFor($office);

        return [
            'office' => ['id' => $office->id, 'code' => $office->code, 'name' => $office->name],
            'mode' => $mode->value,
            'mode_label' => $mode->label(),
            'requires_manager' => $office->requires_manager,
            'authorized_user_ids' => $office->documentAccessSetting?->currentAuthorizations
                ->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all() ?? [],
            'members' => $memberships->map(fn ($membership) => [
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'position_title' => $membership->position_title,
                'membership_role' => $membership->membership_role->value,
                'membership_role_label' => $membership->membership_role->label(),
            ])->values(),
        ];
    }
}
