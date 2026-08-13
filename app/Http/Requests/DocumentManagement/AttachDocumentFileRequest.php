<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class AttachDocumentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('manageDocuments', $expedient) ?? false);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }
}
