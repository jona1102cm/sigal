<?php

namespace App\Http\Requests\Organization;

use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignOfficeMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && ($this->user()?->can('manageMemberships', $office) ?? false);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'membership_role' => ['required', Rule::enum(OfficeMembershipRole::class)],
            'position_title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
