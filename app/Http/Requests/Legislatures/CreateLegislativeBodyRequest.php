<?php

namespace App\Http\Requests\Legislatures;

use App\Domain\Legislatures\Enums\LegislativeBodyType;
use App\Models\Legislature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLegislativeBodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $legislature = $this->route('legislature');

        return $legislature instanceof Legislature && ($this->user()?->can('update', $legislature) ?? false);
    }

    public function rules(): array
    {
        return [
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'body_type' => ['required', Rule::enum(LegislativeBodyType::class)],
            'purpose' => [
                Rule::requiredIf($this->input('body_type') === LegislativeBodyType::TemporaryMixedCommission->value),
                'nullable',
                'string',
                'max:2000',
            ],
            'effective_from' => ['required', 'date'],
        ];
    }
}
