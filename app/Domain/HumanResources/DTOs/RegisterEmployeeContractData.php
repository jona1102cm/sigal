<?php

namespace App\Domain\HumanResources\DTOs;

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\HumanResources\Enums\ContractType;
use Carbon\CarbonImmutable;

readonly class RegisterEmployeeContractData
{
    public function __construct(
        public string $identityCard,
        public string $firstNames,
        public string $lastNames,
        public string $mobilePhone,
        public ?string $email,
        public ?string $address,
        public ?string $cuaNumber,
        public CarbonImmutable $birthDate,
        public ?string $militaryServiceBooklet,
        public string $academicDegree,
        public string $profession,
        public ?string $bloodType,
        public ?string $emergencyContact,
        public ContractType $contractType,
        public ?string $contractAmount,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public int $officePositionId,
        public RoleCode $role,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            identityCard: $validated['identity_card'],
            firstNames: $validated['first_names'],
            lastNames: $validated['last_names'],
            mobilePhone: $validated['mobile_phone'],
            email: $validated['email'] ?? null,
            address: $validated['address'] ?? null,
            cuaNumber: $validated['cua_number'] ?? null,
            birthDate: CarbonImmutable::parse($validated['birth_date']),
            militaryServiceBooklet: $validated['military_service_booklet'] ?? null,
            academicDegree: $validated['academic_degree'],
            profession: $validated['profession'],
            bloodType: $validated['blood_type'] ?? null,
            emergencyContact: $validated['emergency_contact'] ?? null,
            contractType: ContractType::from($validated['contract_type']),
            contractAmount: $validated['contract_amount'] ?? null,
            startsOn: CarbonImmutable::parse($validated['starts_on']),
            endsOn: isset($validated['ends_on']) ? CarbonImmutable::parse($validated['ends_on']) : null,
            officePositionId: (int) $validated['office_position_id'],
            role: isset($validated['role']) ? RoleCode::from($validated['role']) : RoleCode::SimpleUser,
        );
    }
}
