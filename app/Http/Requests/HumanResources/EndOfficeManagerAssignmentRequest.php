<?php

namespace App\Http\Requests\HumanResources;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class EndOfficeManagerAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'effective_to' => ['required', 'date'],
            'closure_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
