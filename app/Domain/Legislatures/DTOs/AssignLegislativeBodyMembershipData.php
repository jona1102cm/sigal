<?php

namespace App\Domain\Legislatures\DTOs;

use App\Domain\Legislatures\Enums\LegislativeBodyRole;
use Carbon\CarbonImmutable;

readonly class AssignLegislativeBodyMembershipData
{
    public function __construct(
        public int $legislatureMemberId,
        public LegislativeBodyRole $role,
        public CarbonImmutable $effectiveFrom,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            (int) $validated['legislature_member_id'],
            LegislativeBodyRole::from($validated['role']),
            CarbonImmutable::parse($validated['effective_from']),
        );
    }
}
