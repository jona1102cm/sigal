<?php

namespace App\Domain\DocumentManagement\Enums;

enum SenderType: string
{
    case Person = 'person';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Persona',
            self::Organization => 'Organización',
        };
    }
}
