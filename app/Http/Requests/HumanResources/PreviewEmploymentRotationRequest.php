<?php

namespace App\Http\Requests\HumanResources;

use App\Models\EmploymentContract;
use Illuminate\Foundation\Http\FormRequest;

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
            'office_position_id' => ['required', 'integer', 'exists:office_positions,id'],
            'effective_from' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['nullable', 'string', 'max:4000'],
            'memorandum_number' => ['nullable', 'string', 'max:100'],
            'memorandum_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
