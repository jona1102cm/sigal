<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class DecideExpedientReopeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('approveReopening', $expedient) ?? false);
    }

    public function rules(): array
    {
        return ['decision_note' => ['nullable', 'string']];
    }
}
