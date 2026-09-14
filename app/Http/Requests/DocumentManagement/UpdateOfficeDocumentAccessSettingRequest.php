<?php

namespace App\Http\Requests\DocumentManagement;

use App\Domain\DocumentManagement\Enums\OfficeDocumentAccessMode;
use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficeDocumentAccessSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office && ($this->user()?->can('configureDocumentAccess', $office) ?? false);
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([
                OfficeDocumentAccessMode::ManagerAssignment->value,
                OfficeDocumentAccessMode::AuthorizedTeam->value,
            ])],
            'authorized_user_ids' => ['present', 'array'],
            'authorized_user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
