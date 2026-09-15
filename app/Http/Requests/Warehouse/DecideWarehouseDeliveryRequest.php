<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;

class DecideWarehouseDeliveryRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $materialRequest = $this->route('materialRequest');

        return $materialRequest instanceof MaterialRequest
            && ($this->user()?->can('deliver', $materialRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'not_attended' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'required_if:not_attended,true', 'string', 'max:5000'],
            'lines' => ['nullable', 'required_unless:not_attended,true', 'array', 'min:1'],
            'lines.*.material_request_item_id' => ['required', 'integer', 'distinct', 'exists:material_request_items,id'],
            'lines.*.warehouse_item_id' => ['required', 'integer', 'exists:warehouse_items,id'],
            'lines.*.delivered_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'lines.*.over_delivery_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
