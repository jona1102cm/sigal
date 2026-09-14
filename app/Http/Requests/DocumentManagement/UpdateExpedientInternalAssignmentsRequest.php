<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpedientInternalAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('manageInternalAssignments', $expedient) ?? false);
    }

    public function rules(): array
    {
        return [
            'responsible_user_id' => ['required', 'integer', 'exists:users,id'],
            'collaborator_user_ids' => ['present', 'array'],
            'collaborator_user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
