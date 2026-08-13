<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\ExpedientType;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpedientTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExpedientType::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', 'unique:expedient_types,code'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
        ];
    }
}
