<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\ConfidentialityLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConfidentialityLevel */
class ConfidentialityLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'requires_explicit_access' => $this->requires_explicit_access,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
        ];
    }
}
