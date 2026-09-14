<?php

namespace App\Domain\DocumentManagement\Enums;

enum InternalAssignmentRole: string
{
    case Responsible = 'responsible';
    case Collaborator = 'collaborator';

    public function label(): string
    {
        return match ($this) {
            self::Responsible => 'Responsable operativo',
            self::Collaborator => 'Colaborador de lectura',
        };
    }
}
