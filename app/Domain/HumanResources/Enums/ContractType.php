<?php

namespace App\Domain\HumanResources\Enums;

enum ContractType: string
{
    case Eventual = 'eventual';
    case LineConsultancy = 'line_consultancy';
    case Tgn = 'tgn';
    case Functioning = 'functioning';

    public function label(): string
    {
        return match ($this) {
            self::Eventual => 'Eventual',
            self::LineConsultancy => 'Consultoría de Línea',
            self::Tgn => 'TGN',
            self::Functioning => 'Funcionamiento',
        };
    }
}
