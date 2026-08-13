<?php

namespace App\Http\Requests\HumanResources;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee && ($this->user()?->can('update', $employee) ?? false);
    }

    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'identity_card' => ['required', 'string', 'max:30', Rule::unique('employees', 'identity_card')->ignore($employee->id)],
            'first_names' => ['required', 'string', 'max:255'],
            'last_names' => ['required', 'string', 'max:255'],
            'mobile_phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'cua_number' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['required', 'date', 'before:today'],
            'military_service_booklet' => ['nullable', 'string', 'max:100'],
            'academic_degree' => ['required', 'string', 'max:255'],
            'profession' => ['required', 'string', 'max:255'],
            'blood_type' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
