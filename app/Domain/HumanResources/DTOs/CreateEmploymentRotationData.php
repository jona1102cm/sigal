<?php

namespace App\Domain\HumanResources\DTOs;

use Carbon\CarbonImmutable;

readonly class CreateEmploymentRotationData
{
    public function __construct(
        public int $destinationOfficePositionId,
        public CarbonImmutable $effectiveFrom,
        public ?string $reason,
        public ?string $memorandumNumber,
        public ?CarbonImmutable $memorandumDate,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            destinationOfficePositionId: (int) $validated['office_position_id'],
            effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
            reason: $validated['reason'] ?? null,
            memorandumNumber: $validated['memorandum_number'] ?? null,
            memorandumDate: isset($validated['memorandum_date'])
                ? CarbonImmutable::parse($validated['memorandum_date'])
                : null,
        );
    }
}
