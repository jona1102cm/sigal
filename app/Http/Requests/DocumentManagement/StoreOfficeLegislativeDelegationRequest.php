<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;

class StoreOfficeLegislativeDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $office = $this->route('office');

        return $office instanceof Office
            && ($this->user()?->can('manageLegislativeDelegations', $office) ?? false);
    }

    public function rules(): array
    {
        return [
            'delegate_membership_id' => ['required', 'integer', 'exists:legislative_body_memberships,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
