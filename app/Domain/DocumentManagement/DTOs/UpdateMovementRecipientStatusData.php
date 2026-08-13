<?php

namespace App\Domain\DocumentManagement\DTOs;

use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;

readonly class UpdateMovementRecipientStatusData
{
    public function __construct(
        public MovementRecipientStatus $status,
        public ?string $actionNote,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            status: MovementRecipientStatus::from($validated['status']),
            actionNote: $validated['action_note'] ?? null,
        );
    }
}
