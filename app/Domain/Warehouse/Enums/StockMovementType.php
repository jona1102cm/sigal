<?php

namespace App\Domain\Warehouse\Enums;

enum StockMovementType: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Ingreso',
            self::Exit => 'Salida',
            self::Adjustment => 'Ajuste',
        };
    }
}
