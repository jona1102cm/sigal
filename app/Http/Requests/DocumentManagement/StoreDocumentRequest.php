<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
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
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'issuing_office_id' => ['required', 'integer', 'exists:offices,id'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'office_reference' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['required', 'file'],
            'primary_office_ids' => ['required', 'array', 'min:1'],
            'primary_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'copy_office_ids' => ['nullable', 'array'],
            'copy_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'requires_response' => ['nullable', 'boolean'],
        ];
    }
}
