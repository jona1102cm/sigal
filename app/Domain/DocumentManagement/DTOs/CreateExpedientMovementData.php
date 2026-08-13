<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class CreateExpedientMovementData
{
    /**
     * @param  list<int>  $primaryOfficeIds
     * @param  list<int>  $copyOfficeIds
     */
    public function __construct(
        public int $senderOfficeId,
        public array $primaryOfficeIds,
        public array $copyOfficeIds,
        public bool $requiresResponse,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            senderOfficeId: (int) $validated['sender_office_id'],
            primaryOfficeIds: array_map('intval', $validated['primary_office_ids']),
            copyOfficeIds: array_map('intval', $validated['copy_office_ids'] ?? []),
            requiresResponse: (bool) ($validated['requires_response'] ?? true),
        );
    }

    /** @param array<string, mixed> $validated */
    public static function fromOptionalDerivation(array $validated, int $senderOfficeId): ?self
    {
        if (empty($validated['primary_office_ids'])) {
            return null;
        }

        return new self(
            senderOfficeId: $senderOfficeId,
            primaryOfficeIds: array_map('intval', $validated['primary_office_ids']),
            copyOfficeIds: array_map('intval', $validated['copy_office_ids'] ?? []),
            requiresResponse: (bool) ($validated['requires_response'] ?? true),
        );
    }
}
