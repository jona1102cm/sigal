<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;

class StoreMaterialRequest extends WarehouseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('requesting_office_id') !== null) {
            return;
        }

        $officeIds = $this->user()?->currentOfficeMemberships()->pluck('office_id')->unique()->values();
        $this->merge(['requesting_office_id' => $officeIds?->count() === 1 ? $officeIds->first() : null]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', MaterialRequest::class) ?? false;
    }

    public function rules(): array
    {
        return self::payloadRules();
    }

    /** @return array<string, list<mixed>> */
    public static function payloadRules(): array
    {
        return [
            'requesting_office_id' => ['required', 'integer', 'exists:offices,id'],
            'justification' => ['required', 'string', 'max:5000'],
            'office_reference' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.warehouse_item_id' => ['nullable', 'integer', 'exists:warehouse_items,id'],
            'items.*.measurement_unit_id' => ['required', 'integer', 'exists:measurement_units,id'],
            'items.*.item_name' => ['nullable', 'required_without:items.*.warehouse_item_id', 'string', 'max:180'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'requesting_office_id.required' => 'Seleccione la oficina desde la cual realiza la solicitud.',
            'items.min' => 'Agregue al menos un material.',
            'items.*.item_name.required_without' => 'Describa el material no registrado.',
        ];
    }
}
