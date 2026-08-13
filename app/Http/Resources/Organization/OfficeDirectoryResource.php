<?php

namespace App\Http\Resources\Organization;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Office */
class OfficeDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'supports_staffing' => $this->supports_staffing,
            'requires_manager' => $this->requires_manager,
        ];
    }
}
