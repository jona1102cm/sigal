<?php

namespace App\Domain\DocumentManagement\DTOs;

use Carbon\CarbonImmutable;

readonly class GrantExpedientAccessData
{
    public function __construct(
        public ?int $userId,
        public ?int $officeId,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
        public ?string $reason,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            userId: isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            officeId: isset($validated['office_id']) ? (int) $validated['office_id'] : null,
            effectiveFrom: isset($validated['effective_from']) ? CarbonImmutable::parse($validated['effective_from']) : now()->toImmutable(),
            effectiveTo: isset($validated['effective_to']) ? CarbonImmutable::parse($validated['effective_to'])->endOfDay() : null,
            reason: $validated['reason'] ?? null,
        );
    }
}
