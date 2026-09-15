<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;
use Illuminate\Validation\Rule;

class DecideMaterialRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $materialRequest = $this->route('materialRequest');

        return $materialRequest instanceof MaterialRequest
            && ($this->user()?->can('decide', $materialRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'observe', 'reject'])],
            'notes' => ['nullable', 'required_if:action,observe,reject', 'string', 'max:5000'],
        ];
    }
}
