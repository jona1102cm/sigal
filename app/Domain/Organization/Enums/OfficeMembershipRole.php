<?php

namespace App\Domain\Organization\Enums;

enum OfficeMembershipRole: string
{
    case Manager = 'manager';
    case Official = 'official';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Jefatura',
            self::Official => 'Funcionario',
        };
    }
}
