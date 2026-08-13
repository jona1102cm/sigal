<?php

namespace App\Domain\Legislatures\DTOs;

readonly class UpdateLegislatureData
{
    public function __construct(
        public int $startYear,
        public int $endYear,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            startYear: (int) $validated['start_year'],
            endYear: (int) $validated['end_year'],
        );
    }
}
