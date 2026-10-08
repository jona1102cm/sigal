<?php

namespace App\Domain\HumanResources\DTOs;

readonly class EmployeeBulkImportResult
{
    /**
     * @param  array<int, array{row: int, name: string, email: string, temporary_password: string}>  $credentials
     * @param  array<int, string>  $errors
     * @param  array<int, array{key: string, office_id: int, office_code: string, office_name: string, position_name: string, rows: array<int, int>, office_requires_manager: bool, existing_manager_position: array{id: int, name: string, has_contracts: bool}|null}>  $missingPositions
     */
    public function __construct(
        public int $importedCount,
        public array $credentials,
        public array $errors = [],
        public array $missingPositions = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }

    public function requiresPositionResolution(): bool
    {
        return $this->missingPositions !== [];
    }
}
