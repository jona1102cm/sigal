<?php

namespace App\Http\Requests\HumanResources;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee && ($this->user()?->can('update', $employee) ?? false);
    }

    public function rules(): array
    {
        return [
            'profile_photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120'],
        ];
    }
}
