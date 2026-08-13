<?php

namespace App\Domain\DocumentManagement\Enums;

enum ExpedientOrigin: string
{
    case Internal = 'internal';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Interno',
            self::External => 'Externo',
        };
    }
}
