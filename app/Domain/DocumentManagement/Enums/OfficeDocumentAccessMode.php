<?php

namespace App\Domain\DocumentManagement\Enums;

enum OfficeDocumentAccessMode: string
{
    case ManagerAssignment = 'manager_assignment';
    case AuthorizedTeam = 'authorized_team';
    case AllMembers = 'all_members';

    public function label(): string
    {
        return match ($this) {
            self::ManagerAssignment => 'Asignación individual por la jefatura',
            self::AuthorizedTeam => 'Equipo autorizado permanente',
            self::AllMembers => 'Todos los miembros de la oficina',
        };
    }
}
