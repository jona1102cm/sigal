<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class CreateExpedientTypeData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $category,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            code: $validated['code'],
            name: $validated['name'],
            category: $validated['category'],
        );
    }
}
