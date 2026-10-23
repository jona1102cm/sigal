<?php

namespace App\Http\Requests\HumanResources;

class StoreEmploymentRotationRequest extends PreviewEmploymentRotationRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'attachment' => ['nullable', 'file', 'max:20480'],
        ];
    }
}
