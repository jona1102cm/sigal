<?php

namespace App\Http\Requests\HumanResources;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class ExtendEmploymentContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return ['ends_on' => ['required', 'date']];
    }
}
