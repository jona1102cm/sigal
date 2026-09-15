<?php

namespace App\Http\Controllers\Api\Warehouse;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Warehouse\Services\WarehouseCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\StoreWarehouseCategoryRequest;
use App\Http\Requests\Warehouse\StoreWarehouseItemRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseCategoryRequest;
use App\Http\Requests\Warehouse\UpdateWarehouseItemRequest;
use App\Http\Resources\Warehouse\MeasurementUnitResource;
use App\Http\Resources\Warehouse\WarehouseCategoryResource;
use App\Http\Resources\Warehouse\WarehouseItemResource;
use App\Models\MeasurementUnit;
use App\Models\WarehouseCategory;
use App\Models\WarehouseItem;
use App\Models\WarehouseReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseCatalogController extends Controller
{
    public function __construct(private readonly WarehouseCatalogService $catalogService) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WarehouseItem::class);

        return response()->json([
            'measurement_units' => MeasurementUnitResource::collection(MeasurementUnit::query()->orderBy('name')->get()),
            'categories' => WarehouseCategoryResource::collection(WarehouseCategory::query()->with('parent')->orderBy('name')->get()),
            'items' => WarehouseItemResource::collection(WarehouseItem::query()->with(['category', 'measurementUnit'])->orderBy('name')->get()),
            'permissions' => [
                'manage_catalog' => $request->user()->can('create', WarehouseItem::class),
                'operate' => $request->user()->can('create', WarehouseReceipt::class),
            ],
        ]);
    }

    public function storeCategory(StoreWarehouseCategoryRequest $request): JsonResponse
    {
        $category = $this->catalogService->createCategory($request->validated(), $request->user(), RequestAuditContext::fromRequest($request));

        return (new WarehouseCategoryResource($category))->response()->setStatusCode(201);
    }

    public function updateCategory(UpdateWarehouseCategoryRequest $request, WarehouseCategory $warehouseCategory): WarehouseCategoryResource
    {
        return new WarehouseCategoryResource($this->catalogService->updateCategory($warehouseCategory, $request->validated(), $request->user(), RequestAuditContext::fromRequest($request)));
    }

    public function storeItem(StoreWarehouseItemRequest $request): JsonResponse
    {
        $item = $this->catalogService->createItem($request->validated(), $request->user(), RequestAuditContext::fromRequest($request));

        return (new WarehouseItemResource($item))->response()->setStatusCode(201);
    }

    public function updateItem(UpdateWarehouseItemRequest $request, WarehouseItem $warehouseItem): WarehouseItemResource
    {
        return new WarehouseItemResource($this->catalogService->updateItem($warehouseItem, $request->validated(), $request->user(), RequestAuditContext::fromRequest($request)));
    }
}
