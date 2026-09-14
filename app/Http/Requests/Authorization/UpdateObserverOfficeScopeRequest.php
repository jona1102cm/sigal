<?php

namespace App\Http\Requests\Authorization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateObserverOfficeScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'office_ids' => ['present', 'array'],
            'office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
        ];
    }
}
