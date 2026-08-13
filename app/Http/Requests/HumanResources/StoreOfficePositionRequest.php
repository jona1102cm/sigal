<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'name' => ['required', 'string', 'max:255'],
            'membership_role' => ['required', Rule::enum(OfficeMembershipRole::class)],
        ];
    }
}
