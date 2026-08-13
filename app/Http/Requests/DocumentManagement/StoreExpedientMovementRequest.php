<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpedientMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('move', $expedient) ?? false);
    }

    public function rules(): array
    {
        return [
            'sender_office_id' => ['required', 'integer', 'exists:offices,id'],
            'primary_office_ids' => ['required', 'array', 'min:1'],
            'primary_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'copy_office_ids' => ['nullable', 'array'],
            'copy_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'requires_response' => ['nullable', 'boolean'],
        ];
    }
}
