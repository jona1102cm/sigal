<?php

namespace App\Domain\DocumentManagement\Enums;

enum OfficeCapabilityCode: string
{
    case ArchiveExpedients = 'archive_expedients';
    case CloseExpedients = 'close_expedients';
    case VoidExpedients = 'void_expedients';
    case ApproveReopenings = 'approve_reopenings';

    public function label(): string
    {
        return match ($this) {
            self::ArchiveExpedients => 'Archivar expedientes',
            self::CloseExpedients => 'Cerrar expedientes',
            self::VoidExpedients => 'Anular expedientes',
            self::ApproveReopenings => 'Aprobar reaperturas',
        };
    }
}
