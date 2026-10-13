<?php

namespace App\Http\Requests\Legislatures;

use App\Domain\Legislatures\Enums\LegislativeBodyRole;
use App\Models\LegislativeBody;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignLegislativeBodyMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $body = $this->route('legislativeBody');

        return $body instanceof LegislativeBody && ($this->user()?->can('update', $body->legislature) ?? false);
    }

    public function rules(): array
    {
        return [
            'legislature_member_id' => ['required', 'integer', 'exists:legislature_members,id'],
            'role' => ['required', Rule::enum(LegislativeBodyRole::class)],
            'effective_from' => ['required', 'date'],
        ];
    }
}
