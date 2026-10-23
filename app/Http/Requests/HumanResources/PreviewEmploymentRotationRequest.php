<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\HumanResources\Enums\EmploymentRotationPositionMode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\EmploymentContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewEmploymentRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof EmploymentContract
            && ($this->user()?->can('manageRotations', $contract->employee) ?? false);
    }

    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'position_mode' => [
                'required',
                Rule::in([
                    EmploymentRotationPositionMode::PreserveCurrent->value,
                    EmploymentRotationPositionMode::CreateNew->value,
                ]),
            ],
            'new_position_name' => [
                Rule::requiredIf($this->input('position_mode') === EmploymentRotationPositionMode::CreateNew->value),
                Rule::prohibitedIf($this->input('position_mode') !== EmploymentRotationPositionMode::CreateNew->value),
                'string',
                'max:255',
            ],
            'new_position_membership_role' => [
                Rule::requiredIf($this->input('position_mode') === EmploymentRotationPositionMode::CreateNew->value),
                Rule::prohibitedIf($this->input('position_mode') !== EmploymentRotationPositionMode::CreateNew->value),
                Rule::enum(OfficeMembershipRole::class),
            ],
            'effective_from' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['nullable', 'string', 'max:4000'],
            'memorandum_number' => ['nullable', 'string', 'max:100'],
            'memorandum_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
