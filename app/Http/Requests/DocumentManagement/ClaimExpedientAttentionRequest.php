<?php

namespace App\Http\Requests\DocumentManagement;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;

class ClaimExpedientAttentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expedient = $this->route('expedient');

        return $expedient instanceof Expedient
            && ($this->user()?->can('view', $expedient) ?? false)
            && ($this->user()?->hasPermission(PermissionCode::ExpedientsProcess) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
