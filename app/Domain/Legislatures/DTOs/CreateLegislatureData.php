<?php

namespace App\Domain\Legislatures\DTOs;

use App\Domain\Legislatures\Enums\LegislatureStatus;

readonly class CreateLegislatureData
{
    public function __construct(
        public int $startYear,
        public int $endYear,
        public LegislatureStatus $status,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            startYear: (int) $validated['start_year'],
            endYear: (int) $validated['end_year'],
            status: LegislatureStatus::from($validated['status']),
        );
    }
}
