<?php

namespace App\Http\Resources\Warehouse;

use App\Models\WarehouseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseCategory */
class WarehouseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
