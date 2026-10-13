<?php

namespace App\Domain\Legislatures\Enums;

enum LegislativeBodyType: string
{
    case Caucus = 'caucus';
    case PermanentCommission = 'permanent_commission';
    case TemporaryMixedCommission = 'temporary_mixed_commission';

    public function label(): string
    {
        return match ($this) {
            self::Caucus => 'Bancada',
            self::PermanentCommission => 'Comisión permanente',
            self::TemporaryMixedCommission => 'Comisión mixta temporal',
        };
    }

    public function isCommission(): bool
    {
        return $this !== self::Caucus;
    }
}
