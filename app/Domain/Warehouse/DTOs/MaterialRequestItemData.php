<?php

namespace App\Domain\Warehouse\DTOs;

readonly class MaterialRequestItemData
{
    public function __construct(
        public ?int $warehouseItemId,
        public int $measurementUnitId,
        public ?string $itemName,
        public string $requestedQuantity,
        public ?string $notes,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['warehouse_item_id']) ? (int) $data['warehouse_item_id'] : null,
            (int) $data['measurement_unit_id'],
            isset($data['item_name']) ? trim($data['item_name']) : null,
            (string) $data['requested_quantity'],
            isset($data['notes']) ? trim($data['notes']) : null,
        );
    }
}
