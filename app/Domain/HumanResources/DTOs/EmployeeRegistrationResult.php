<?php

namespace App\Domain\HumanResources\DTOs;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;

readonly class EmployeeRegistrationResult
{
    public function __construct(
        public Employee $employee,
        public EmploymentContract $contract,
        public User $user,
        public ?string $temporaryPassword,
    ) {}
}
