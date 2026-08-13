<?php

namespace App\Http\Requests\Legislatures;

use App\Models\Legislature;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLegislatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $legislature = $this->route('legislature');

        return $legislature instanceof Legislature
            && ($this->user()?->can('update', $legislature) ?? false);
    }

    public function rules(): array
    {
        return [
            'start_year' => ['required', 'integer'],
            'end_year' => ['required', 'integer'],
        ];
    }
}
