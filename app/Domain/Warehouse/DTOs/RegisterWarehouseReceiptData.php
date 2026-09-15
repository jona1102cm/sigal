<?php

namespace App\Domain\Warehouse\DTOs;

readonly class RegisterWarehouseReceiptData
{
    /** @param list<WarehouseReceiptLineData> $lines */
    public function __construct(
        public string $supplierName,
        public ?string $supplierTaxId,
        public string $referenceType,
        public string $referenceNumber,
        public string $referenceDate,
        public string $receivedOn,
        public ?string $observations,
        public array $lines,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            trim($validated['supplier_name']),
            $validated['supplier_tax_id'] ?? null,
            $validated['reference_type'],
            trim($validated['reference_number']),
            $validated['reference_date'],
            $validated['received_on'],
            $validated['observations'] ?? null,
            array_map(WarehouseReceiptLineData::fromArray(...), $validated['lines']),
        );
    }
}
