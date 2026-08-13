<?php

namespace App\Domain\Organization\DTOs;

readonly class UpdateOfficeData
{
    public function __construct(
        public ?int $parentId,
        public string $code,
        public string $name,
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
            supportsStaffing: (bool) $validated['supports_staffing'],
            requiresManager: (bool) $validated['requires_manager'],
        );
    }
}
