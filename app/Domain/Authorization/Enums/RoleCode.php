<?php

namespace App\Domain\Authorization\Enums;

enum RoleCode: string
{
    case SuperAdministrator = 'super_administrator';
    case HumanResourcesManager = 'human_resources_manager';
    case Observer = 'observer';
    case SimpleUser = 'simple_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Superadministrador',
            self::HumanResourcesManager => 'Administrador de Recursos Humanos',
            self::Observer => 'Observador',
            self::SimpleUser => 'Usuario simple',
        };
    }
}
