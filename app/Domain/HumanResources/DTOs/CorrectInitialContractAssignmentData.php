<?php

namespace App\Domain\HumanResources\DTOs;

use App\Domain\Organization\Enums\OfficeMembershipRole;

/** Datos confirmados para corregir una oficina cargada erróneamente desde el inicio. */
readonly class CorrectInitialContractAssignmentData
{
    public function __construct(
        public int $targetOfficeId,
        public OfficeMembershipRole $membershipRole,
        public string $reason,
    ) {}
}
