<?php

namespace App\Domain\DocumentManagement\Enums;

/** Identifica excepciones internas controladas al enrutamiento documental ordinario. */
enum InstitutionalWorkflow: string
{
    case WarehouseMaterialRequest = 'warehouse_material_request';
}
