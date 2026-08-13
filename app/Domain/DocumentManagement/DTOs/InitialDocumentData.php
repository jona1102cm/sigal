<?php

namespace App\Domain\DocumentManagement\DTOs;

use Carbon\CarbonImmutable;

readonly class InitialDocumentData
{
    public function __construct(
        public int $documentTypeId,
        public string $title,
        public ?string $content,
        public string $originNumber,
        public CarbonImmutable $originDate,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            documentTypeId: (int) $validated['document_type_id'],
            title: $validated['subject'],
            content: $validated['content'] ?? null,
            originNumber: $validated['origin_document_number'],
            originDate: CarbonImmutable::parse($validated['origin_document_date']),
        );
    }
}
