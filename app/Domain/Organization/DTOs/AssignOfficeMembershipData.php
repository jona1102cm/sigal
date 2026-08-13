<?php

namespace App\Domain\Organization\DTOs;

use App\Domain\Organization\Enums\OfficeMembershipRole;

readonly class AssignOfficeMembershipData
{
    public function __construct(
        public int $userId,
        public OfficeMembershipRole $membershipRole,
        public ?string $positionTitle,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            userId: (int) $validated['user_id'],
            membershipRole: OfficeMembershipRole::from($validated['membership_role']),
            positionTitle: $validated['position_title'] ?? null,
        );
    }
}
