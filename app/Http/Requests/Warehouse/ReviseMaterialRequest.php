<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;

class ReviseMaterialRequest extends UpdateMaterialRequest
{
    public function authorize(): bool
    {
        $materialRequest = $this->route('materialRequest');

        return $materialRequest instanceof MaterialRequest
            && ($this->user()?->can('revise', $materialRequest) ?? false);
    }
}
