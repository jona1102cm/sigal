<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;

class ConfirmWarehouseDeliveryRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $materialRequest = $this->route('materialRequest');

        return $materialRequest instanceof MaterialRequest
            && ($this->user()?->can('confirmReceipt', $materialRequest) ?? false);
    }

    public function rules(): array
    {
        return ['observations' => ['nullable', 'string', 'max:3000']];
    }
}
