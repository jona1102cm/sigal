<?php

namespace App\Domain\HumanResources\DTOs;

readonly class EmployeeBulkImportResult
{
    /**
     * @param  array<int, array{row: int, name: string, email: string, temporary_password: string}>  $credentials
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public int $importedCount,
        public array $credentials,
        public array $errors = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->errors === [];
    }
}
