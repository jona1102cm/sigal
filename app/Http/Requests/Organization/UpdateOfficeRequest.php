<?php

namespace App\Http\Requests\Organization;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && ($this->user()?->can('update', $office) ?? false);
    }

    public function rules(): array
    {
        /** @var Office $office */
        $office = $this->route('office');

        return [
            'parent_id' => ['nullable', 'integer', 'exists:offices,id'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('offices', 'code')->ignore($office)],
            'name' => ['required', 'string', 'max:255'],
            'supports_staffing' => ['required', 'boolean'],
            'requires_manager' => ['required', 'boolean'],
        ];
    }
}
