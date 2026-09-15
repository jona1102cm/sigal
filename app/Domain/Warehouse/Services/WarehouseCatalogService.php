<?php

namespace App\Domain\Warehouse\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\MeasurementUnit;
use App\Models\User;
use App\Models\WarehouseCategory;
use App\Models\WarehouseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseCatalogService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @param array<string, mixed> $data */
    public function createCategory(array $data, User $actor, RequestAuditContext $context): WarehouseCategory
    {
        return DB::transaction(function () use ($data, $actor, $context): WarehouseCategory {
            if (isset($data['parent_id'])) {
                WarehouseCategory::query()->where('status', CatalogStatus::Active->value)->findOrFail($data['parent_id']);
            }

            $category = WarehouseCategory::query()->create([
                'parent_id' => $data['parent_id'] ?? null,
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => mb_strtoupper(trim($data['name'])),
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? CatalogStatus::Active,
                'created_by' => $actor->id,
            ]);
            $this->activityLogger->record('warehouse.category.created', $actor, $category, $context, newValues: $category->toArray());

            return $category->load('parent');
        });
    }

    /** @param array<string, mixed> $data */
    public function createItem(array $data, User $actor, RequestAuditContext $context): WarehouseItem
    {
        return DB::transaction(function () use ($data, $actor, $context): WarehouseItem {
            WarehouseCategory::query()->where('status', CatalogStatus::Active->value)->findOrFail($data['warehouse_category_id']);
            MeasurementUnit::query()->where('status', CatalogStatus::Active->value)->findOrFail($data['measurement_unit_id']);

            $item = WarehouseItem::query()->create([
                'warehouse_category_id' => $data['warehouse_category_id'],
                'measurement_unit_id' => $data['measurement_unit_id'],
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => mb_strtoupper(trim($data['name'])),
                'description' => $data['description'] ?? null,
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'physical_location' => $data['physical_location'] ?? null,
                'status' => $data['status'] ?? CatalogStatus::Active,
                'created_by' => $actor->id,
            ]);
            $this->activityLogger->record('warehouse.item.created', $actor, $item, $context, newValues: $item->toArray());

            return $item->load(['category', 'measurementUnit']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(WarehouseCategory $category, array $data, User $actor, RequestAuditContext $context): WarehouseCategory
    {
        return DB::transaction(function () use ($category, $data, $actor, $context): WarehouseCategory {
            $target = WarehouseCategory::query()->lockForUpdate()->findOrFail($category->id);
            $this->assertValidParent($target, isset($data['parent_id']) ? (int) $data['parent_id'] : null);
            if (($data['status'] ?? null) === CatalogStatus::Inactive->value
                && $target->items()->where('status', CatalogStatus::Active->value)->exists()) {
                throw ValidationException::withMessages(['status' => 'No puede inactivar una categoría que todavía contiene materiales activos.']);
            }
            $old = $target->toArray();
            $target->update([
                'parent_id' => $data['parent_id'] ?? null,
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => mb_strtoupper(trim($data['name'])),
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
            ]);
            $this->activityLogger->record('warehouse.category.updated', $actor, $target, $context, oldValues: $old, newValues: $target->toArray());

            return $target->load('parent');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateItem(WarehouseItem $item, array $data, User $actor, RequestAuditContext $context): WarehouseItem
    {
        return DB::transaction(function () use ($item, $data, $actor, $context): WarehouseItem {
            $target = WarehouseItem::query()->lockForUpdate()->findOrFail($item->id);
            WarehouseCategory::query()->where('status', CatalogStatus::Active->value)->findOrFail($data['warehouse_category_id']);
            MeasurementUnit::query()->where('status', CatalogStatus::Active->value)->findOrFail($data['measurement_unit_id']);
            if ((int) $target->measurement_unit_id !== (int) $data['measurement_unit_id'] && $target->stockMovements()->exists()) {
                throw ValidationException::withMessages(['measurement_unit_id' => 'La unidad base no puede cambiar después de registrar movimientos en el kardex.']);
            }
            if (($data['status'] ?? null) === CatalogStatus::Inactive->value && bccomp((string) $target->stock_on_hand, '0', 4) > 0) {
                throw ValidationException::withMessages(['status' => 'No puede inactivar un material mientras conserve existencias.']);
            }
            $old = $target->toArray();
            $target->update([
                'warehouse_category_id' => $data['warehouse_category_id'],
                'measurement_unit_id' => $data['measurement_unit_id'],
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => mb_strtoupper(trim($data['name'])),
                'description' => $data['description'] ?? null,
                'minimum_stock' => $data['minimum_stock'],
                'physical_location' => $data['physical_location'] ?? null,
                'status' => $data['status'],
            ]);
            $this->activityLogger->record('warehouse.item.updated', $actor, $target, $context, oldValues: $old, newValues: $target->toArray());

            return $target->load(['category', 'measurementUnit']);
        });
    }

    private function assertValidParent(WarehouseCategory $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = WarehouseCategory::query()->where('status', CatalogStatus::Active->value)->findOrFail($parentId);
        while ($parent !== null) {
            if ((int) $parent->id === (int) $category->id) {
                throw ValidationException::withMessages(['parent_id' => 'La jerarquía seleccionada produciría un ciclo entre categorías.']);
            }
            $parent = $parent->parent_id ? WarehouseCategory::query()->find($parent->parent_id) : null;
        }
    }
}
