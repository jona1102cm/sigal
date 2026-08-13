<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use App\Models\ExpedientMovement;
use Illuminate\Foundation\Http\FormRequest;

class LinkDocumentToMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && $this->user() !== null
            && ExpedientMovement::query()
                ->where('expedient_id', $expedient->id)
                ->where('sent_by', $this->user()->id)
                ->whereKey($this->input('movement_id'))
                ->exists();
    }

    public function rules(): array
    {
        return ['movement_id' => ['required', 'integer', 'exists:expedient_movements,id']];
    }
}
