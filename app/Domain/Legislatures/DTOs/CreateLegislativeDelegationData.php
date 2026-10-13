<?php

namespace App\Domain\Legislatures\DTOs;

use Carbon\CarbonImmutable;

readonly class CreateLegislativeDelegationData
{
    public function __construct(
        public int $principalMembershipId,
        public int $delegateMembershipId,
        public CarbonImmutable $effectiveFrom,
        public CarbonImmutable $effectiveTo,
        public string $reason,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            (int) $validated['principal_membership_id'],
            (int) $validated['delegate_membership_id'],
            CarbonImmutable::parse($validated['effective_from']),
            CarbonImmutable::parse($validated['effective_to']),
            $validated['reason'],
        );
    }
}
