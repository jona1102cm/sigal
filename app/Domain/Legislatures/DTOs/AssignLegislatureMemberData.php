<?php

namespace App\Domain\Legislatures\DTOs;

use Carbon\CarbonImmutable;

readonly class AssignLegislatureMemberData
{
    public function __construct(public int $userId, public CarbonImmutable $effectiveFrom) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self((int) $validated['user_id'], CarbonImmutable::parse($validated['effective_from']));
    }
}
