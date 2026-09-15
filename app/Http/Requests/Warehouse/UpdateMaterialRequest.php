<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;

class UpdateMaterialRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $materialRequest = $this->route('materialRequest');

        return $materialRequest instanceof MaterialRequest
            && ($this->user()?->can('update', $materialRequest) ?? false);
    }

    public function rules(): array
    {
        return StoreMaterialRequest::payloadRules();
    }
}
