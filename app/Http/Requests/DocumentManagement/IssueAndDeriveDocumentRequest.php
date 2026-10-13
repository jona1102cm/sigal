<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

/** Valida la emisión y salida atómica de un borrador preparado previamente. */
class IssueAndDeriveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('manageDocuments', $expedient) ?? false);
    }

    public function rules(): array
    {
        return [
            'primary_office_ids' => ['required', 'array', 'min:1'],
            'primary_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'copy_office_ids' => ['nullable', 'array'],
            'copy_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'primary_recipient_attention' => ['nullable', 'array'],
            'primary_recipient_attention.*' => ['nullable', 'integer', 'exists:legislature_members,id'],
            'requires_response' => ['nullable', 'boolean'],
        ];
    }
}
