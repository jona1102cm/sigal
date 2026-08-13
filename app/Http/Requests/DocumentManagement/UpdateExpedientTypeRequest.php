<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\ExpedientType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpedientTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedientType = $this->route('expedientType');

        return $expedientType instanceof ExpedientType
            && ($this->user()?->can('update', $expedientType) ?? false);
    }

    public function rules(): array
    {
        /** @var ExpedientType $expedientType */
        $expedientType = $this->route('expedientType');

        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('expedient_types', 'code')->ignore($expedientType)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
        ];
    }
}
