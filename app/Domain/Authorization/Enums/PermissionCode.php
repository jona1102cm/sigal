<?php

namespace App\Domain\Authorization\Enums;

/**
 * Catálogo estable de capacidades asignables a los roles institucionales.
 *
 * Los códigos son contratos entre base de datos, Policies y Vue. Los nombres
 * visibles pueden cambiar sin invalidar las asignaciones históricas.
 */
enum PermissionCode: string
{
    case DashboardView = 'dashboard.view';
    case ExpedientsView = 'document_management.expedients.view';
    case ExpedientsCreate = 'document_management.expedients.create';
    case ExpedientsProcess = 'document_management.expedients.process';
    case OfficeAccessConfigure = 'document_management.office_access.configure';
    case HumanResourcesView = 'human_resources.employees.view';
    case HumanResourcesManage = 'human_resources.employees.manage';
    case HumanResourcesImport = 'human_resources.employees.import';
    case HumanResourcesPositions = 'human_resources.positions.manage';
    case HumanResourcesContracts = 'human_resources.contracts.manage';
    case LegislaturesManage = 'administration.legislatures.manage';
    case OrganizationManage = 'administration.organization.manage';
    case ExpedientTypesManage = 'administration.expedient_types.manage';
    case UsersManage = 'administration.users.manage';
    case RolePermissionsManage = 'administration.role_permissions.manage';
    case OperationalResetManage = 'administration.operational_reset.manage';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Ver inicio y resumen documental',
            self::ExpedientsView => 'Ver bandejas y expedientes autorizados',
            self::ExpedientsCreate => 'Registrar nuevos expedientes',
            self::ExpedientsProcess => 'Responder, documentar y derivar expedientes',
            self::OfficeAccessConfigure => 'Configurar el acceso documental de su oficina',
            self::HumanResourcesView => 'Ver funcionarios y kardex',
            self::HumanResourcesManage => 'Crear y actualizar funcionarios y documentos de kardex',
            self::HumanResourcesImport => 'Importar funcionarios desde Excel',
            self::HumanResourcesPositions => 'Administrar cargos de oficina',
            self::HumanResourcesContracts => 'Administrar contratos y vigencias',
            self::LegislaturesManage => 'Administrar legislaturas y Directiva',
            self::OrganizationManage => 'Administrar organigrama y oficinas',
            self::ExpedientTypesManage => 'Administrar tipos de expediente',
            self::UsersManage => 'Administrar usuarios, roles, accesos y contraseñas',
            self::RolePermissionsManage => 'Configurar la matriz de permisos',
            self::OperationalResetManage => 'Ejecutar el reinicio preoperativo',
        };
    }

    public function module(): string
    {
        return match ($this) {
            self::DashboardView => 'Inicio',
            self::ExpedientsView, self::ExpedientsCreate, self::ExpedientsProcess, self::OfficeAccessConfigure => 'Gestión documental',
            self::HumanResourcesView, self::HumanResourcesManage, self::HumanResourcesImport,
            self::HumanResourcesPositions, self::HumanResourcesContracts => 'Recursos Humanos',
            default => 'Administración',
        };
    }

    public function section(): string
    {
        return match ($this) {
            self::DashboardView => 'Panel principal',
            self::ExpedientsView, self::ExpedientsCreate, self::ExpedientsProcess => 'Expedientes',
            self::OfficeAccessConfigure => 'Configuración de oficina',
            self::HumanResourcesView, self::HumanResourcesManage, self::HumanResourcesImport => 'Funcionarios y kardex',
            self::HumanResourcesPositions => 'Cargos',
            self::HumanResourcesContracts => 'Contratos',
            self::LegislaturesManage => 'Legislaturas y Directiva',
            self::OrganizationManage => 'Organigrama y oficinas',
            self::ExpedientTypesManage => 'Tipos de expediente',
            self::UsersManage => 'Usuarios y accesos',
            self::RolePermissionsManage => 'Roles y permisos',
            self::OperationalResetManage => 'Reinicio preoperativo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ExpedientsView => 'La visibilidad real también se limita por oficina, asignación, confidencialidad y alcance de observación.',
            self::ExpedientsProcess => 'No permite actuar si la oficina o el funcionario no poseen la custodia vigente.',
            self::OfficeAccessConfigure => 'La Policy exige además ser responsable vigente de la oficina.',
            self::UsersManage, self::RolePermissionsManage => 'Capacidad reservada al rol Superadministrador.',
            default => 'Habilita esta vista o acción; el backend vuelve a validar el alcance de los datos.',
        };
    }

    public function isSuperAdministratorOnly(): bool
    {
        return str_starts_with($this->value, 'administration.');
    }

    /** @return list<self> */
    public static function defaultsFor(RoleCode $role): array
    {
        return match ($role) {
            RoleCode::SuperAdministrator => self::cases(),
            RoleCode::HumanResourcesManager => [
                self::HumanResourcesView,
                self::HumanResourcesManage,
                self::HumanResourcesImport,
                self::HumanResourcesPositions,
                self::HumanResourcesContracts,
            ],
            RoleCode::Observer => [
                self::DashboardView,
                self::ExpedientsView,
            ],
            RoleCode::SimpleUser => [
                self::DashboardView,
                self::ExpedientsView,
                self::ExpedientsCreate,
                self::ExpedientsProcess,
                self::OfficeAccessConfigure,
            ],
        };
    }
}
