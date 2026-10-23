<?php

namespace App\Domain\HumanResources\DTOs;

use App\Domain\HumanResources\Enums\EmploymentRotationPositionMode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use Carbon\CarbonImmutable;

readonly class CreateEmploymentRotationData
{
    public function __construct(
        public int $destinationOfficeId,
        public EmploymentRotationPositionMode $positionMode,
        public ?string $newPositionName,
        public ?OfficeMembershipRole $newPositionMembershipRole,
        public CarbonImmutable $effectiveFrom,
        public ?string $reason,
        public ?string $memorandumNumber,
        public ?CarbonImmutable $memorandumDate,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            destinationOfficeId: (int) $validated['office_id'],
            positionMode: EmploymentRotationPositionMode::from($validated['position_mode']),
            newPositionName: isset($validated['new_position_name'])
                ? trim($validated['new_position_name'])
                : null,
            newPositionMembershipRole: isset($validated['new_position_membership_role'])
                ? OfficeMembershipRole::from($validated['new_position_membership_role'])
                : null,
            effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
            reason: $validated['reason'] ?? null,
            memorandumNumber: $validated['memorandum_number'] ?? null,
            memorandumDate: isset($validated['memorandum_date'])
                ? CarbonImmutable::parse($validated['memorandum_date'])
                : null,
        );
    }
}
