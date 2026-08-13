<?php

namespace App\Domain\Authorization\DTOs;

use App\Models\User;

readonly class EmergencyPasswordResetResult
{
    public function __construct(
        public User $user,
        public string $temporaryPassword,
    ) {}
}
