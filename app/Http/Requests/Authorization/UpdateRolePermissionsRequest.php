<?php

namespace App\Http\Requests\Authorization;

use App\Domain\Authorization\Enums\PermissionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(PermissionCode::class)],
        ];
    }
}
