<?php

namespace App\Http\Requests\Warehouse;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\WarehouseItem;
use Illuminate\Validation\Rule;

class StoreWarehouseItemRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WarehouseItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'warehouse_category_id' => ['required', 'integer', 'exists:warehouse_categories,id'],
            'measurement_unit_id' => ['required', 'integer', 'exists:measurement_units,id'],
            'code' => ['required', 'string', 'max:60', 'unique:warehouse_items,code'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0', 'decimal:0,4'],
            'physical_location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
        ];
    }
}
