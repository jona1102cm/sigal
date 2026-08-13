<?php

namespace App\Domain\DocumentManagement\Enums;

enum MovementRecipientKind: string
{
    case Primary = 'primary';
    case Copy = 'copy';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Destinatario principal',
            self::Copy => 'Copia',
        };
    }
}
