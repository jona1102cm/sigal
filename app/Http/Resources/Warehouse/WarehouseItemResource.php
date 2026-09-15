<?php

namespace App\Http\Resources\Warehouse;

use App\Models\WarehouseItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseItem */
class WarehouseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'minimum_stock' => $this->minimum_stock,
            'stock_on_hand' => $this->stock_on_hand,
            'is_below_minimum' => bccomp((string) $this->stock_on_hand, (string) $this->minimum_stock, 4) <= 0,
            'physical_location' => $this->physical_location,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'category' => $this->whenLoaded('category', fn () => new WarehouseCategoryResource($this->category)),
            'measurement_unit' => $this->whenLoaded('measurementUnit', fn () => new MeasurementUnitResource($this->measurementUnit)),
        ];
    }
}
