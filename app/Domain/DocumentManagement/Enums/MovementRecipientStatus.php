<?php

namespace App\Domain\DocumentManagement\Enums;

enum MovementRecipientStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case InProcess = 'in_process';
    case Responded = 'responded';
    case Returned = 'returned';
    case Rejected = 'rejected';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Received => 'Recibido',
            self::InProcess => 'En proceso',
            self::Responded => 'Respondido',
            self::Returned => 'Devuelto',
            self::Rejected => 'Rechazado',
            self::Completed => 'Finalizado',
        };
    }
}
