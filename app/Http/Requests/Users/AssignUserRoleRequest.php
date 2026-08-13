<?php

namespace App\Http\Requests\Users;

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            && ($this->user()?->can('manageRoles', $user) ?? false);
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(RoleCode::class)],
        ];
    }
}
