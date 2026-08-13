<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class ReopeningDecisionData
{
    public function __construct(public ?string $decisionNote) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(decisionNote: $validated['decision_note'] ?? null);
    }
}
