<?php

namespace App\Http\Controllers\Api\Warehouse;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Warehouse\DTOs\RegisterWarehouseReceiptData;
use App\Domain\Warehouse\Services\WarehouseInventoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\AdjustWarehouseStockRequest;
use App\Http\Requests\Warehouse\StoreWarehouseReceiptRequest;
use App\Http\Resources\Warehouse\WarehouseItemResource;
use App\Http\Resources\Warehouse\WarehouseReceiptResource;
use App\Models\WarehouseItem;
use App\Models\WarehouseReceipt;
use App\Models\WarehouseReceiptAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseReceiptController extends Controller
{
    public function __construct(private readonly WarehouseInventoryService $inventoryService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WarehouseReceipt::class);

        return WarehouseReceiptResource::collection(WarehouseReceipt::query()->with($this->relations())->latest('received_on')->latest('id')->paginate(25));
    }

    public function store(StoreWarehouseReceiptRequest $request): JsonResponse
    {
        $receipt = $this->inventoryService->registerReceipt(RegisterWarehouseReceiptData::fromValidated($request->validated()), $request->file('attachments', []), $request->user(), RequestAuditContext::fromRequest($request));

        return (new WarehouseReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function show(Request $request, WarehouseReceipt $warehouseReceipt): WarehouseReceiptResource
    {
        $this->authorize('view', $warehouseReceipt);

        return new WarehouseReceiptResource($warehouseReceipt->load($this->relations()));
    }

    public function downloadAttachment(Request $request, WarehouseReceipt $warehouseReceipt, WarehouseReceiptAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $warehouseReceipt);
        abort_unless((int) $attachment->warehouse_receipt_id === (int) $warehouseReceipt->id, 404);

        return Storage::disk($attachment->storage_disk)->download($attachment->storage_path, $attachment->original_name);
    }

    public function stockMovements(Request $request, WarehouseItem $warehouseItem): JsonResponse
    {
        $this->authorize('view', $warehouseItem);
        $movements = $warehouseItem->stockMovements()->with('performedBy:id,name')->latest('occurred_at')->latest('id')->paginate(50);

        return response()->json([
            'data' => $movements->map(fn ($movement) => [
                'id' => $movement->id,
                'movement_type' => $movement->movement_type->value,
                'movement_type_label' => $movement->movement_type->label(),
                'quantity_delta' => $movement->quantity_delta,
                'balance_after' => $movement->balance_after,
                'unit_cost' => $movement->unit_cost,
                'reason' => $movement->reason,
                'occurred_at' => $movement->occurred_at->toIso8601String(),
                'performed_by' => ['id' => $movement->performedBy->id, 'name' => $movement->performedBy->name],
            ]),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }

    public function adjust(AdjustWarehouseStockRequest $request, WarehouseItem $warehouseItem): WarehouseItemResource
    {
        return new WarehouseItemResource($this->inventoryService->adjust($warehouseItem, (string) $request->validated('quantity_delta'), $request->validated('reason'), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['lines.item.category', 'lines.item.measurementUnit', 'attachments', 'registeredBy', 'warehouseResponsible'];
    }
}
