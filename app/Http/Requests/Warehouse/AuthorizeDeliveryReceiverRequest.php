<?php

namespace App\Http\Requests\Warehouse;

use App\Models\MaterialRequest;

class AuthorizeDeliveryReceiverRequest extends WarehouseFormRequest
{
    public function authorize(): bool
    {
        $materialRequest = $this->route('materialRequest');

        return $materialRequest instanceof MaterialRequest
            && ($this->user()?->can('authorizeReceiver', $materialRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'receiver_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
