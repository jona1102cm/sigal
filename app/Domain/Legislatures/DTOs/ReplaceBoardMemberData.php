<?php

namespace App\Domain\Legislatures\DTOs;

use App\Domain\Legislatures\Enums\BoardPosition;
use Carbon\CarbonImmutable;

readonly class ReplaceBoardMemberData
{
    public function __construct(
        public int $userId,
        public BoardPosition $position,
        public CarbonImmutable $effectiveOn,
        public CarbonImmutable $effectiveAt,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            userId: (int) $validated['user_id'],
            position: BoardPosition::from($validated['position']),
            effectiveOn: CarbonImmutable::parse($validated['effective_on'])->startOfDay(),
            effectiveAt: CarbonImmutable::parse($validated['effective_at']),
        );
    }
}
