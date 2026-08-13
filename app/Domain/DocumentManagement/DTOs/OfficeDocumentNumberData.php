<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class OfficeDocumentNumberData
{
    public function __construct(
        public string $prefix,
        public int $padding,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            prefix: $validated['prefix'],
            padding: (int) $validated['padding'],
        );
    }
}
