<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\HumanResources\Enums\EmployeeAttachmentType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee && ($this->user()?->can('update', $employee) ?? false);
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in([
                EmployeeAttachmentType::RejapCertificate->value,
                EmployeeAttachmentType::CenviCertificate->value,
                EmployeeAttachmentType::ElectoralRegistryCertificate->value,
                EmployeeAttachmentType::OtherSupportingDocument->value,
            ])],
            'attachment' => ['required', 'file', 'max:20480'],
        ];
    }
}
