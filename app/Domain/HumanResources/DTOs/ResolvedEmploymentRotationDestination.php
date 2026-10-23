<?php

namespace App\Domain\HumanResources\DTOs;

use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Office;
use App\Models\OfficePosition;

/** Destino operativo ya validado, antes o después de crear su cargo. */
readonly class ResolvedEmploymentRotationDestination
{
    public function __construct(
        public Office $office,
        public string $positionTitle,
        public OfficeMembershipRole $membershipRole,
        public ?OfficePosition $officePosition = null,
    ) {}

    public function withOfficePosition(OfficePosition $officePosition): self
    {
        return new self(
            office: $this->office,
            positionTitle: $officePosition->name,
            membershipRole: $officePosition->membership_role,
            officePosition: $officePosition,
        );
    }
}
