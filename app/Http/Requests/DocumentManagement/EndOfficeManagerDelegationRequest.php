<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;

class EndOfficeManagerDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && ($this->user()?->can('manageManagerDelegations', $office) ?? false);
    }

    public function rules(): array
    {
        return ['effective_to' => ['required', 'date']];
    }
}
