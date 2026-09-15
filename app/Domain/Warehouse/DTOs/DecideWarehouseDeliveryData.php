<?php

namespace App\Domain\Warehouse\DTOs;

readonly class DecideWarehouseDeliveryData
{
    /** @param list<WarehouseDeliveryLineData> $lines */
    public function __construct(
        public bool $notAttended,
        public ?string $reason,
        public array $lines,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            (bool) ($validated['not_attended'] ?? false),
            $validated['reason'] ?? null,
            array_map(WarehouseDeliveryLineData::fromArray(...), $validated['lines'] ?? []),
        );
    }
}
