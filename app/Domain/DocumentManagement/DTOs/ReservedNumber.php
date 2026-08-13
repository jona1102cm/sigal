<?php

namespace App\Domain\DocumentManagement\DTOs;

readonly class ReservedNumber
{
    public function __construct(
        public int $number,
        public string $formatted,
    ) {}
}
