<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class LifecycleActionData
{
    public function __construct(public string $reason) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(reason: $validated['reason']);
    }
}
