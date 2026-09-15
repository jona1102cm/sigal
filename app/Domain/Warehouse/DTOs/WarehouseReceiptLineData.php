<?php

namespace App\Domain\Warehouse\DTOs;

readonly class WarehouseReceiptLineData
{
    public function __construct(
        public int $warehouseItemId,
        public string $quantity,
        public string $unitCost,
        public ?string $lotNumber,
        public ?string $expiresOn,
        public ?string $physicalLocation,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['warehouse_item_id'],
            (string) $data['quantity'],
            (string) $data['unit_cost'],
            $data['lot_number'] ?? null,
            $data['expires_on'] ?? null,
            $data['physical_location'] ?? null,
        );
    }
}
