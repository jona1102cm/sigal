<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class PerformOperationalResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('perform-operational-reset') ?? false;
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:REINICIAR SIGAL'],
        ];
    }
}
