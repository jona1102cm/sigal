<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class GrantExpedientAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('manageAccess', $expedient) ?? false);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
