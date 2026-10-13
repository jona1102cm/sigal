<?php

namespace App\Http\Requests\Legislatures;

use Illuminate\Foundation\Http\FormRequest;

class CloseLegislativeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('legislature')) ?? false;
    }

    public function rules(): array
    {
        return ['effective_to' => ['required', 'date']];
    }
}
