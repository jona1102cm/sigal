<?php

namespace App\Http\Requests\DocumentManagement;

use App\Domain\DocumentManagement\Enums\ExpedientPriority;
use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentedExpedientRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('priority')) {
            $this->merge(['priority' => ExpedientPriority::Normal->value]);
        }

        StoreExpedientRequest::resolveSingleResponsibleOffice($this);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Expedient::class) ?? false;
    }

    public function rules(): array
    {
        return [
            ...StoreExpedientRequest::registrationRules(),
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'origin_document_number' => ['required', 'string', 'max:255'],
            'origin_document_date' => ['required', 'date_format:Y-m-d'],
            'content' => ['nullable', 'string', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'required_without:content', 'min:1'],
            'attachments.*' => ['required', 'file'],
        ];
    }

    public function messages(): array
    {
        return [
            ...StoreExpedientRequest::registrationMessages(),
            'document_type_id.exists' => 'El tipo de documento seleccionado ya no está disponible. Actualice el formulario y vuelva a seleccionarlo.',
        ];
    }
}
