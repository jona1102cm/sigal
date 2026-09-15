<?php

namespace App\Http\Requests\Warehouse;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\WarehouseItem;
use Illuminate\Validation\Rule;

class UpdateWarehouseItemRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('warehouseItem');

        return $item instanceof WarehouseItem && ($this->user()?->can('update', $item) ?? false);
    }

    public function rules(): array
    {
        $item = $this->route('warehouseItem');

        return [
            'warehouse_category_id' => ['required', 'integer', 'exists:warehouse_categories,id'],
            'measurement_unit_id' => ['required', 'integer', 'exists:measurement_units,id'],
            'code' => ['required', 'string', 'max:60', Rule::unique('warehouse_items', 'code')->ignore($item)],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'physical_location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }
}
