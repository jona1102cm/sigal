<?php

namespace App\Domain\HumanResources\DTOs;

use App\Domain\Organization\Enums\OfficeMembershipRole;

readonly class CreateOfficePositionData
{
    public function __construct(
        public int $officeId,
        public string $name,
        public OfficeMembershipRole $membershipRole,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            officeId: (int) $validated['office_id'],
            name: $validated['name'],
            membershipRole: OfficeMembershipRole::from($validated['membership_role']),
        );
    }
}
