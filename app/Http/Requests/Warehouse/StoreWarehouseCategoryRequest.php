<?php

namespace App\Http\Requests\Warehouse;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\WarehouseCategory;
use Illuminate\Validation\Rule;

class StoreWarehouseCategoryRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WarehouseCategory::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:warehouse_categories,id'],
            'code' => ['required', 'string', 'max:50', 'unique:warehouse_categories,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
        ];
    }
}
