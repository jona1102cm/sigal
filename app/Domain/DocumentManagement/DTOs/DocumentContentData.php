<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class DocumentContentData
{
    public function __construct(
        public string $title,
        public ?string $content,
        public ?string $officeReference,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $officeReference = trim((string) ($validated['office_reference'] ?? ''));

        return new self(
            title: $validated['title'],
            content: $validated['content'] ?? null,
            officeReference: $officeReference === '' ? null : mb_strtoupper($officeReference),
        );
    }
}
