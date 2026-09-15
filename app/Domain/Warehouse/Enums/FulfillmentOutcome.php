<?php

namespace App\Domain\Warehouse\Enums;

enum FulfillmentOutcome: string
{
    case Full = 'full';
    case Partial = 'partial';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Atendida completamente',
            self::Partial => 'Atendida parcialmente',
            self::None => 'No atendida',
        };
    }
}
