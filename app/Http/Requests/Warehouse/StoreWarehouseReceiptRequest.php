<?php

namespace App\Http\Requests\Warehouse;

use App\Models\WarehouseReceipt;
use Illuminate\Validation\Rule;

class StoreWarehouseReceiptRequest extends WarehouseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('lines'))) {
            $this->merge(['lines' => json_decode($this->input('lines'), true)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', WarehouseReceipt::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_name' => ['required', 'string', 'max:180'],
            'supplier_tax_id' => ['nullable', 'string', 'max:40'],
            'reference_type' => ['required', Rule::in(['invoice', 'note'])],
            'reference_number' => ['required', 'string', 'max:100'],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.warehouse_item_id' => ['required', 'integer', 'exists:warehouse_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:received_on'],
            'lines.*.physical_location' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:20480'],
        ];
    }
}
