<?php

namespace App\Http\Requests\Legislatures;

use App\Domain\Legislatures\Enums\BoardPosition;
use App\Models\Legislature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceBoardMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'position' => ['required', Rule::enum(BoardPosition::class)],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'effective_at' => ['required', 'date'],
        ];
    }
}
