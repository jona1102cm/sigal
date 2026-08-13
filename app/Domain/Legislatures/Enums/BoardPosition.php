<?php

namespace App\Domain\Legislatures\Enums;

enum BoardPosition: string
{
    case President = 'president';
    case VicePresident = 'vice_president';
    case SecondVicePresident = 'second_vice_president';
    case Secretary = 'secretary';
    case SecondSecretary = 'second_secretary';

    public function label(): string
    {
        return match ($this) {
            self::President => 'Presidente',
            self::VicePresident => 'Vicepresidente',
            self::SecondVicePresident => 'Segundo Vicepresidente',
            self::Secretary => 'Secretaria',
            self::SecondSecretary => 'Segunda Secretaria',
        };
    }
}
