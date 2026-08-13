<?php

namespace App\Domain\HumanResources\DTOs;

use Carbon\CarbonImmutable;

readonly class UpdateEmployeeData
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
        );
    }
}
