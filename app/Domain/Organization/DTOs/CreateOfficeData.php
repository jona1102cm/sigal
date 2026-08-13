<?php

namespace App\Domain\Organization\DTOs;

use App\Domain\Organization\Enums\OfficeStatus;

readonly class CreateOfficeData
{
    public function __construct(
        public ?int $parentId,
        public string $code,
        public string $name,
        public OfficeStatus $status,
        public bool $supportsStaffing,
        public bool $requiresManager,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            parentId: $validated['parent_id'] ?? null,
            code: $validated['code'],
            name: $validated['name'],
            status: OfficeStatus::from($validated['status']),
            supportsStaffing: (bool) $validated['supports_staffing'],
            requiresManager: (bool) $validated['requires_manager'],
        );
    }
}
