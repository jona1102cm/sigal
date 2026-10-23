<?php

namespace App\Domain\Organization\DTOs;

use Carbon\CarbonImmutable;

readonly class CreateOfficeManagerDelegationData
{
    public function __construct(
        public int $delegateUserId,
        public CarbonImmutable $effectiveFrom,
        public CarbonImmutable $effectiveTo,
        public string $reason,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            delegateUserId: (int) $validated['delegate_user_id'],
            effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
            effectiveTo: CarbonImmutable::parse($validated['effective_to']),
            reason: trim($validated['reason']),
        );
    }
}
