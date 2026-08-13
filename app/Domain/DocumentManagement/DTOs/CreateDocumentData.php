<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class CreateDocumentData
{
    public function __construct(
        public int $documentTypeId,
        public int $issuingOfficeId,
        public DocumentContentData $content,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            documentTypeId: (int) $validated['document_type_id'],
            issuingOfficeId: (int) $validated['issuing_office_id'],
            content: DocumentContentData::fromValidated($validated),
        );
    }
}
