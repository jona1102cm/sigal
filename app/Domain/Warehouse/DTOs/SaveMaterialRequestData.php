<?php

namespace App\Domain\Warehouse\DTOs;

readonly class SaveMaterialRequestData
{
    /** @param list<MaterialRequestItemData> $items */
    public function __construct(
        public int $requestingOfficeId,
        public string $justification,
        public ?string $officeReference,
        public array $items,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $reference = trim((string) ($validated['office_reference'] ?? ''));

        return new self(
            (int) $validated['requesting_office_id'],
            trim($validated['justification']),
            $reference === '' ? null : mb_strtoupper($reference),
            array_map(MaterialRequestItemData::fromArray(...), $validated['items']),
        );
    }
}
