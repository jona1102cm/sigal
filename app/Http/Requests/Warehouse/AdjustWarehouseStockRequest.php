<?php

namespace App\Http\Requests\Warehouse;

use App\Models\WarehouseItem;

class AdjustWarehouseStockRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('warehouseItem');

        return $item instanceof WarehouseItem && ($this->user()?->can('adjust', $item) ?? false);
    }

    public function rules(): array
    {
        return [
            'quantity_delta' => ['required', 'numeric', 'not_in:0', 'decimal:0,4'],
            'reason' => ['required', 'string', 'max:3000'],
        ];
    }
}
