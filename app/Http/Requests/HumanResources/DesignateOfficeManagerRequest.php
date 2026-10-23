<?php

namespace App\Http\Requests\HumanResources;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class DesignateOfficeManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'effective_from' => ['required', 'date', 'before_or_equal:now'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'designation_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
