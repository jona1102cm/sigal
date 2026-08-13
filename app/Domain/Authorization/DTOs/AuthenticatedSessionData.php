<?php

namespace App\Domain\Authorization\DTOs;

use App\Models\User;

readonly class AuthenticatedSessionData
{
    public function __construct(
        public User $user,
        public string $plainTextToken,
    ) {}
}
