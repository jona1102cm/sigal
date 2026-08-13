<?php

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Enums\OfficeStatus;
use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Office::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:offices,id'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', 'unique:offices,code'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(OfficeStatus::class)],
            'supports_staffing' => ['required', 'boolean'],
            'requires_manager' => ['required', 'boolean'],
        ];
    }
}
