<?php

namespace App\Domain\HumanResources\DTOs;

use App\Domain\Organization\Enums\OfficeMembershipRole;

readonly class UpdateOfficePositionData
{
    public function __construct(
        public string $name,
        public OfficeMembershipRole $membershipRole,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            name: $validated['name'],
            membershipRole: OfficeMembershipRole::from($validated['membership_role']),
        );
    }
}
