<?php

namespace App\Domain\Warehouse\Enums;

/** Estado de negocio de una solicitud, independiente de la oficina que posee el expediente. */
enum MaterialRequestStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Observed = 'observed';
    case InAttention = 'in_attention';
    case PendingReceipt = 'pending_receipt';
    case Received = 'received';
    case NotAttended = 'not_attended';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Pending => 'Pendiente',
            self::Observed => 'Observada',
            self::InAttention => 'En atención por Almacenes',
            self::PendingReceipt => 'Pendiente de confirmación',
            self::Received => 'Recepcionada',
            self::NotAttended => 'No atendida',
            self::Rejected => 'Rechazada',
            self::Closed => 'Cerrada',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::NotAttended, self::Rejected, self::Closed], true);
    }
}
