<?php

namespace App\Http\Requests\Legislatures;

use App\Domain\Legislatures\Enums\LegislatureStatus;
use App\Models\Legislature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLegislatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Legislature::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'start_year' => ['required', 'integer'],
            'end_year' => ['required', 'integer'],
            'status' => ['required', Rule::enum(LegislatureStatus::class)],
        ];
    }
}
