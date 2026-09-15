<?php

namespace App\Domain\Warehouse\Enums;

/** Etapa organizacional que complementa el estado sin duplicar la derivación documental. */
enum MaterialRequestStage: string
{
    case Draft = 'draft';
    case UnitManager = 'unit_manager';
    case Omaf = 'omaf';
    case GoodsServices = 'goods_services';
    case Warehouse = 'warehouse';
    case Receipt = 'receipt';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Preparación',
            self::UnitManager => 'Responsable de la oficina solicitante',
            self::Omaf => 'OMAF',
            self::GoodsServices => 'Bienes y Servicios',
            self::Warehouse => 'Activos Fijos y Almacenes',
            self::Receipt => 'Confirmación de recepción',
            self::Completed => 'Finalizada',
        };
    }
}
