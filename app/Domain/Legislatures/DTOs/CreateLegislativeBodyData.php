<?php

namespace App\Domain\Legislatures\DTOs;

use App\Domain\Legislatures\Enums\LegislativeBodyType;
use Carbon\CarbonImmutable;

readonly class CreateLegislativeBodyData
{
    public function __construct(
        public int $officeId,
        public LegislativeBodyType $bodyType,
        public ?string $purpose,
        public CarbonImmutable $effectiveFrom,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            (int) $validated['office_id'],
            LegislativeBodyType::from($validated['body_type']),
            $validated['purpose'] ?? null,
            CarbonImmutable::parse($validated['effective_from']),
        );
    }
}
