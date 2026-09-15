<?php

namespace App\Domain\Warehouse\DTOs;

readonly class WarehouseDeliveryLineData
{
    public function __construct(
        public int $materialRequestItemId,
        public int $warehouseItemId,
        public string $deliveredQuantity,
        public ?string $overDeliveryReason,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['material_request_item_id'],
            (int) $data['warehouse_item_id'],
            (string) $data['delivered_quantity'],
            $data['over_delivery_reason'] ?? null,
        );
    }
}
