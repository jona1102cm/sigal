<?php

namespace App\Domain\HumanResources\Enums;

/** Define cómo se determina el cargo operativo al registrar una rotación. */
enum EmploymentRotationPositionMode: string
{
    case PreserveCurrent = 'preserve_current';
    case CreateNew = 'create_new';

    /** Valor histórico para rotaciones creadas antes de incorporar los dos modos explícitos. */
    case LegacyExisting = 'legacy_existing';

    public function label(): string
    {
        return match ($this) {
            self::PreserveCurrent => 'Conserva el cargo anterior',
            self::CreateNew => 'Cargo nuevo creado en destino',
            self::LegacyExisting => 'Cargo existente asignado históricamente',
        };
    }
}
