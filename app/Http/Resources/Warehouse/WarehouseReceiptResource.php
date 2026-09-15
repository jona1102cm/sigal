<?php

namespace App\Http\Resources\Warehouse;

use App\Models\WarehouseReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseReceipt */
class WarehouseReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'supplier_name' => $this->supplier_name,
            'supplier_tax_id' => $this->supplier_tax_id,
            'reference_type' => $this->reference_type,
            'reference_type_label' => $this->reference_type === 'invoice' ? 'Factura' : 'Nota',
            'reference_number' => $this->reference_number,
            'reference_date' => $this->reference_date->toDateString(),
            'received_on' => $this->received_on->toDateString(),
            'currency' => $this->currency,
            'total_amount' => $this->total_amount,
            'observations' => $this->observations,
            'posted_at' => $this->posted_at->toIso8601String(),
            'registered_by' => $this->whenLoaded('registeredBy', fn () => ['id' => $this->registeredBy->id, 'name' => $this->registeredBy->name]),
            'warehouse_responsible' => $this->whenLoaded('warehouseResponsible', fn () => ['id' => $this->warehouseResponsible->id, 'name' => $this->warehouseResponsible->name]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost,
                'subtotal' => $line->subtotal,
                'lot_number' => $line->lot_number,
                'expires_on' => $line->expires_on?->toDateString(),
                'physical_location' => $line->physical_location,
                'item' => $line->relationLoaded('item') ? new WarehouseItemResource($line->item) : null,
            ])->values()),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ])->values()),
        ];
    }
}
