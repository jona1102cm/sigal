<?php

namespace App\Domain\HumanResources\DTOs;

use Carbon\CarbonImmutable;

readonly class DesignateOfficeManagerData
{
    public function __construct(
        public int $userId,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
        public ?string $designationNote,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            userId: (int) $validated['user_id'],
            effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
            effectiveTo: isset($validated['effective_to'])
                ? CarbonImmutable::parse($validated['effective_to'])
                : null,
            designationNote: $validated['designation_note'] ?? null,
        );
    }
}
