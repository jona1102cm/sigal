<?php

namespace App\Domain\Legislatures\Enums;

enum LegislativeBodyRole: string
{
    case Chief = 'chief';
    case DeputyChief = 'deputy_chief';
    case President = 'president';
    case VicePresident = 'vice_president';
    case Secretary = 'secretary';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Chief => 'Jefe',
            self::DeputyChief => 'Subjefe',
            self::President => 'Presidente',
            self::VicePresident => 'Vicepresidente',
            self::Secretary => 'Secretario(a)',
            self::Member => 'Miembro',
        };
    }

    public function isResponsible(): bool
    {
        return in_array($this, [self::Chief, self::President], true);
    }

    public function canReceiveDelegationFrom(self $principal): bool
    {
        return ($principal === self::Chief && $this === self::DeputyChief)
            || ($principal === self::President && $this === self::VicePresident);
    }

    /** @return list<self> */
    public static function forBodyType(LegislativeBodyType $type): array
    {
        if ($type === LegislativeBodyType::Caucus) {
            return [self::Chief, self::DeputyChief, self::Member];
        }

        return [self::President, self::VicePresident, self::Secretary, self::Member];
    }
}
