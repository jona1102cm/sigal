<?php

namespace App\Http\Requests\DocumentManagement;

use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class LifecycleActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');
        $ability = match ($this->route()?->getActionMethod()) {
            'archive' => 'archive',
            'close' => 'close',
            'void' => 'void',
            default => null,
        };

        return $expedient instanceof Expedient
            && $ability !== null
            && ($this->user()?->can($ability, $expedient) ?? false);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string']];
    }
}
