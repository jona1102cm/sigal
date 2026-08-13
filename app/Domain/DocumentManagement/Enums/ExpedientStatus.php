<?php

namespace App\Domain\DocumentManagement\Enums;

enum ExpedientStatus: string
{
    case Registered = 'registered';
    case InProcess = 'in_process';
    case Derived = 'derived';
    case PendingResponse = 'pending_response';
    case PartiallyResponded = 'partially_responded';
    case FullyResponded = 'fully_responded';
    case Observed = 'observed';
    case Archived = 'archived';
    case Closed = 'closed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registrado',
            self::InProcess => 'En trámite',
            self::Derived => 'Derivado',
            self::PendingResponse => 'Pendiente de respuesta',
            self::PartiallyResponded => 'Respondido parcialmente',
            self::FullyResponded => 'Respondido completamente',
            self::Observed => 'Observado',
            self::Archived => 'Archivado',
            self::Closed => 'Cerrado',
            self::Voided => 'Anulado',
        };
    }
}
