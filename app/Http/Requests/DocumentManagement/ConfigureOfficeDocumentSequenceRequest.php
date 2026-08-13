<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Legislature;
use Illuminate\Foundation\Http\FormRequest;

class ConfigureOfficeDocumentSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $legislature = $this->route('legislature');

        return $legislature instanceof Legislature
            && ($this->user()?->can('update', $legislature) ?? false);
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/'],
            'padding' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
