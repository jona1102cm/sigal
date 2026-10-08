<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
            'position_resolutions' => ['sometimes', 'array', 'max:1000'],
            'position_resolutions.*.key' => ['required', 'string', 'size:64', 'distinct'],
            'position_resolutions.*.membership_role' => ['required', Rule::enum(OfficeMembershipRole::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $resolutions = $this->input('position_resolutions');

        if (! is_string($resolutions)) {
            return;
        }

        $decoded = json_decode($resolutions, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->merge(['position_resolutions' => $decoded]);
        }
    }
}
