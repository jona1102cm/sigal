<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class LifecycleActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('manageLifecycle', $expedient) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string']];
    }
}
