<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Employee;
use App\Models\OfficePosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        /** @var OfficePosition|null $position */
        $position = $this->route('officePosition');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('office_positions', 'name')
                    ->where('office_id', $position?->office_id)
                    ->ignore($position),
            ],
            'membership_role' => ['required', Rule::enum(OfficeMembershipRole::class)],
        ];
    }
}
