<?php

namespace App\Http\Resources\Warehouse;

use App\Models\MeasurementUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MeasurementUnit */
class MeasurementUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'symbol' => $this->symbol, 'allows_fraction' => $this->allows_fraction, 'status' => $this->status->value];
    }
}
