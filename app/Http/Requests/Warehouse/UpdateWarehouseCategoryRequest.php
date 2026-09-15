<?php

namespace App\Http\Requests\Warehouse;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\WarehouseCategory;
use Illuminate\Validation\Rule;

class UpdateWarehouseCategoryRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('warehouseCategory');

        return $category instanceof WarehouseCategory && ($this->user()?->can('update', $category) ?? false);
    }

    public function rules(): array
    {
        $category = $this->route('warehouseCategory');

        return [
            'parent_id' => ['nullable', 'integer', Rule::notIn([$category->id]), 'exists:warehouse_categories,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('warehouse_categories', 'code')->ignore($category)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }
}
