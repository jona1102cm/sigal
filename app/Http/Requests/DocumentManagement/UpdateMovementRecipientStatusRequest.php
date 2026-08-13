<?php

namespace App\Http\Requests\DocumentManagement;

use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMovementRecipientStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('actOnMovement', $expedient) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MovementRecipientStatus::class)],
            'action_note' => ['nullable', 'string'],
        ];
    }
}
