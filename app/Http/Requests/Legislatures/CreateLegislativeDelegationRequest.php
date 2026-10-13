<?php

namespace App\Http\Requests\Legislatures;

use App\Models\LegislativeBody;
use Illuminate\Foundation\Http\FormRequest;

class CreateLegislativeDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $body = $this->route('legislativeBody');

        return $body instanceof LegislativeBody && ($this->user()?->can('update', $body->legislature) ?? false);
    }

    public function rules(): array
    {
        return [
            'principal_membership_id' => ['required', 'integer', 'different:delegate_membership_id', 'exists:legislative_body_memberships,id'],
            'delegate_membership_id' => ['required', 'integer', 'exists:legislative_body_memberships,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
