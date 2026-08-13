<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\HumanResources\Enums\ContractType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterEmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'identity_card' => ['required', 'string', 'max:30'],
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
            'contract_type' => ['required', Rule::enum(ContractType::class)],
            'contract_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'office_position_id' => ['required', 'integer', 'exists:office_positions,id'],
            'role' => ['nullable', Rule::enum(RoleCode::class)],
            'rejap_certificate' => ['nullable', 'file', 'max:20480'],
            'cenvi_certificate' => ['nullable', 'file', 'max:20480'],
            'electoral_registry_certificate' => ['nullable', 'file', 'max:20480'],
            'profile_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120'],
        ];
    }
}
