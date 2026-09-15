<?php

namespace App\Http\Controllers\Api\Warehouse;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Warehouse\DTOs\DecideWarehouseDeliveryData;
use App\Domain\Warehouse\DTOs\SaveMaterialRequestData;
use App\Domain\Warehouse\Enums\MaterialRequestStatus;
use App\Domain\Warehouse\Services\MaterialRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\AuthorizeDeliveryReceiverRequest;
use App\Http\Requests\Warehouse\ConfirmWarehouseDeliveryRequest;
use App\Http\Requests\Warehouse\DecideMaterialRequest;
use App\Http\Requests\Warehouse\DecideWarehouseDeliveryRequest;
use App\Http\Requests\Warehouse\ReviseMaterialRequest;
use App\Http\Requests\Warehouse\StoreMaterialRequest;
use App\Http\Requests\Warehouse\UpdateMaterialRequest;
use App\Http\Resources\Warehouse\MaterialRequestResource;
use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialRequestController extends Controller
{
    public function __construct(private readonly MaterialRequestService $requestService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialRequest::class);
        $user = $request->user();
        $query = MaterialRequest::query()->with($this->relations());

        if (! $user->isSuperAdministrator()) {
            $query->where(function (Builder $visible) use ($user): void {
                $visible->where('requesting_user_id', $user->id)
                    ->orWhereHas('expedient', fn (Builder $expedient) => $expedient->visibleTo($user));
            });
        }

        $scope = $request->string('scope', 'active')->toString();
        if ($scope === 'mine') {
            $query->where('requesting_user_id', $user->id);
        } elseif ($scope === 'active') {
            $query->whereNotIn('status', [MaterialRequestStatus::Closed->value, MaterialRequestStatus::Rejected->value, MaterialRequestStatus::NotAttended->value]);
        } elseif ($scope === 'history') {
            $query->whereIn('status', [MaterialRequestStatus::Closed->value, MaterialRequestStatus::Rejected->value, MaterialRequestStatus::NotAttended->value]);
        }

        if ($search = trim($request->string('search')->toString())) {
            $query->where(function (Builder $filter) use ($search): void {
                $filter->where('justification', 'ilike', "%{$search}%")
                    ->orWhereHas('expedient', fn (Builder $expedient) => $expedient->where('route_code', 'ilike', "%{$search}%"))
                    ->orWhereHas('items', fn (Builder $item) => $item->where('item_name', 'ilike', "%{$search}%"));
            });
        }

        return MaterialRequestResource::collection($query->latest('id')->paginate(25));
    }

    public function store(StoreMaterialRequest $request): JsonResponse
    {
        $materialRequest = $this->requestService->createDraft(SaveMaterialRequestData::fromValidated($request->validated()), $request->user(), RequestAuditContext::fromRequest($request));

        return (new MaterialRequestResource($materialRequest))->response()->setStatusCode(201);
    }

    public function show(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        $this->authorize('view', $materialRequest);

        return new MaterialRequestResource($materialRequest->load($this->relations()));
    }

    public function update(UpdateMaterialRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return new MaterialRequestResource($this->requestService->updateDraft($materialRequest, SaveMaterialRequestData::fromValidated($request->validated()), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function submit(Request $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        $this->authorize('submit', $materialRequest);

        return new MaterialRequestResource($this->requestService->submit($materialRequest, $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function decide(DecideMaterialRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return new MaterialRequestResource($this->requestService->decide($materialRequest, $request->validated(), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function revise(ReviseMaterialRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return new MaterialRequestResource($this->requestService->revise($materialRequest, SaveMaterialRequestData::fromValidated($request->validated()), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function decideDelivery(DecideWarehouseDeliveryRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return new MaterialRequestResource($this->requestService->decideDelivery($materialRequest, DecideWarehouseDeliveryData::fromValidated($request->validated()), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function authorizeReceiver(AuthorizeDeliveryReceiverRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return new MaterialRequestResource($this->requestService->authorizeReceiver($materialRequest, (int) $request->validated('receiver_user_id'), $request->validated('reason'), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function confirmReceipt(ConfirmWarehouseDeliveryRequest $request, MaterialRequest $materialRequest): MaterialRequestResource
    {
        return new MaterialRequestResource($this->requestService->confirmReceipt($materialRequest, $request->validated('observations'), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function eligibleReceivers(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        $this->authorize('authorizeReceiver', $materialRequest);
        $users = User::query()->where('status', 'active')
            ->whereKeyNot($materialRequest->delivery?->delivered_by)
            ->whereHas('currentOfficeMemberships', fn ($membership) => $membership->where('office_id', $materialRequest->requesting_office_id))
            ->orderBy('name')->get(['id', 'name', 'email']);

        return response()->json(['data' => $users]);
    }

    public function act(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        $this->authorize('viewAct', $materialRequest);
        $materialRequest->load($this->relations());

        return response()->json(['data' => new MaterialRequestResource($materialRequest)]);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'expedient', 'requestDocument.numberSeries', 'requestingUser', 'requestingOffice',
            'items.warehouseItem.category', 'items.warehouseItem.measurementUnit', 'items.measurementUnit',
            'decisions.actor', 'decisions.office', 'delivery.lines.item.category', 'delivery.lines.item.measurementUnit',
            'delivery.lines.requestItem', 'delivery.actDocument.numberSeries', 'delivery.deliveredBy', 'delivery.warehouseResponsible',
            'delivery.authorizedReceiver', 'delivery.receiverAuthorizedBy', 'delivery.confirmedBy',
        ];
    }
}
