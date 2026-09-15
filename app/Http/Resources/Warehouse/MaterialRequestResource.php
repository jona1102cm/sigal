<?php

namespace App\Http\Resources\Warehouse;

use App\Models\MaterialRequest;
use App\Policies\MaterialRequestPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialRequest */
class MaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentItems = $this->relationLoaded('items')
            ? $this->items->where('revision_number', $this->current_revision_number)->sortBy('sort_order')->values()
            : collect();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_stage' => $this->current_stage->value,
            'current_stage_label' => $this->current_stage->label(),
            'display_status' => $this->displayStatus(),
            'fulfillment_outcome' => $this->fulfillment_outcome?->value,
            'fulfillment_outcome_label' => $this->fulfillment_outcome?->label(),
            'current_revision_number' => $this->current_revision_number,
            'justification' => $this->justification,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'delivery_decided_at' => $this->delivery_decided_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'expedient' => $this->whenLoaded('expedient', fn () => [
                'id' => $this->expedient->id,
                'route_code' => $this->expedient->route_code,
                'subject' => $this->expedient->subject,
                'priority' => $this->expedient->priority->value,
            ]),
            'document' => $this->whenLoaded('requestDocument', fn () => [
                'id' => $this->requestDocument->id,
                'office_reference' => $this->requestDocument->office_reference,
                'official_number' => $this->requestDocument->numberSeries?->formatted_number,
                'status' => $this->requestDocument->status->value,
            ]),
            'requesting_user' => $this->whenLoaded('requestingUser', fn () => ['id' => $this->requestingUser->id, 'name' => $this->requestingUser->name]),
            'requesting_office' => $this->whenLoaded('requestingOffice', fn () => ['id' => $this->requestingOffice->id, 'code' => $this->requestingOffice->code, 'name' => $this->requestingOffice->name]),
            'items' => $currentItems->map(fn ($item) => [
                'id' => $item->id,
                'warehouse_item_id' => $item->warehouse_item_id,
                'item_name' => $item->item_name,
                'requested_quantity' => $item->requested_quantity,
                'notes' => $item->notes,
                'measurement_unit' => $item->relationLoaded('measurementUnit') ? new MeasurementUnitResource($item->measurementUnit) : null,
                'warehouse_item' => $item->relationLoaded('warehouseItem') && $item->warehouseItem ? new WarehouseItemResource($item->warehouseItem) : null,
            ])->values(),
            'decisions' => $this->whenLoaded('decisions', fn () => $this->decisions->sortByDesc('decided_at')->map(fn ($decision) => [
                'id' => $decision->id,
                'action' => $decision->action,
                'from_status' => $decision->from_status,
                'to_status' => $decision->to_status,
                'from_stage' => $decision->from_stage,
                'to_stage' => $decision->to_stage,
                'notes' => $decision->notes,
                'metadata' => $decision->metadata,
                'decided_at' => $decision->decided_at->toIso8601String(),
                'actor' => $decision->relationLoaded('actor') ? ['id' => $decision->actor->id, 'name' => $decision->actor->name] : null,
                'office' => $decision->relationLoaded('office') && $decision->office ? ['id' => $decision->office->id, 'code' => $decision->office->code, 'name' => $decision->office->name] : null,
            ])->values()),
            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery ? new WarehouseDeliveryResource($this->delivery) : null),
            'actions' => $request->user() ? app(MaterialRequestPolicy::class)->actions($request->user(), $this->resource) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function displayStatus(): string
    {
        if ($this->status->value === 'pending') {
            return 'Pendiente — '.$this->current_stage->label();
        }
        if ($this->status->value === 'pending_receipt' && $this->fulfillment_outcome) {
            return $this->status->label().' — '.$this->fulfillment_outcome->label();
        }
        if ($this->status->value === 'closed' && $this->fulfillment_outcome) {
            return $this->status->label().' — '.$this->fulfillment_outcome->label();
        }

        return $this->status->label();
    }
}
