<?php

namespace App\Http\Requests\Legislatures;

use App\Models\Legislature;
use Illuminate\Foundation\Http\FormRequest;

class AssignLegislatureMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $legislature = $this->route('legislature');

        return $legislature instanceof Legislature && ($this->user()?->can('update', $legislature) ?? false);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
