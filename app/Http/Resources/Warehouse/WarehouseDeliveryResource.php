<?php

namespace App\Http\Resources\Warehouse;

use App\Models\WarehouseDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseDelivery */
class WarehouseDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = fn ($model) => $model === null ? null : ['id' => $model->id, 'name' => $model->name];

        return [
            'id' => $this->id,
            'delivery_number' => $this->delivery_number,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmation_observations' => $this->confirmation_observations,
            'receiver_authorization_reason' => $this->receiver_authorization_reason,
            'receiver_authorized_at' => $this->receiver_authorized_at?->toIso8601String(),
            'act_verification_code' => $this->act_verification_code,
            'act_hash' => $this->act_hash,
            'act_document' => $this->whenLoaded('actDocument', fn () => $this->actDocument ? [
                'id' => $this->actDocument->id,
                'official_number' => $this->actDocument->numberSeries?->formatted_number,
                'title' => $this->actDocument->title,
            ] : null),
            'delivered_by' => $this->whenLoaded('deliveredBy', fn () => $user($this->deliveredBy)),
            'warehouse_responsible' => $this->whenLoaded('warehouseResponsible', fn () => $user($this->warehouseResponsible)),
            'authorized_receiver' => $this->whenLoaded('authorizedReceiver', fn () => $user($this->authorizedReceiver)),
            'receiver_authorized_by' => $this->whenLoaded('receiverAuthorizedBy', fn () => $user($this->receiverAuthorizedBy)),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $user($this->confirmedBy)),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'material_request_item_id' => $line->material_request_item_id,
                'requested_quantity' => $line->requested_quantity_snapshot,
                'delivered_quantity' => $line->delivered_quantity,
                'over_delivery_reason' => $line->over_delivery_reason,
                'item' => $line->relationLoaded('item') ? new WarehouseItemResource($line->item) : null,
            ])->values()),
        ];
    }
}
