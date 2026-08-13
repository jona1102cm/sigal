<?php

namespace App\Console\Commands;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Crea o asegura la cuenta institucional inicial con administración completa. */
class BootstrapSuperAdministrator extends Command
{
    protected $signature = 'sigal:bootstrap-superadministrator
        {--name= : Nombre del superadministrador inicial}
        {--email= : Correo del superadministrador inicial}';

    protected $description = 'Crea el primer superadministrador y muestra una contraseña de un solo uso.';

    public function handle(ActivityLogger $activityLogger): int
    {
        $name = $this->option('name') ?: 'Superadministrador SIGAL';
        $email = $this->option('email') ?: 'superadministrador@sigal.local';
        $plainTextPassword = Str::password(24, symbols: true);

        [$user, $wasCreated] = DB::transaction(function () use ($name, $email, $plainTextPassword, $activityLogger): array {
            $role = Role::query()->firstOrCreate([
                'code' => RoleCode::SuperAdministrator->value,
            ], [
                'name' => RoleCode::SuperAdministrator->label(),
            ]);

            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $wasCreated = $user === null;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $plainTextPassword,
                    'must_change_password' => true,
                    'status' => UserStatus::Active,
                ]);
            }

            $assignment = UserRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->whereNull('effective_to')
                ->first();

            if ($assignment === null) {
                $assignment = UserRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'effective_from' => now(),
                ]);
            }

            $activityLogger->record(
                event: 'authorization.initial_superadministrator_bootstrapped',
                actor: null,
                subject: $user,
                context: new RequestAuditContext(null, 'artisan'),
                newValues: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => RoleCode::SuperAdministrator->value,
                    'user_created' => $wasCreated,
                    'role_assignment_id' => $assignment->id,
                ],
            );

            return [$user, $wasCreated];
        });

        if (! $wasCreated) {
            $this->warn("El usuario {$user->email} ya existía; no se modificó su contraseña.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Superadministrador inicial creado. Guarde la contraseña y cámbiela después del primer acceso.');
        $this->line("Correo: {$user->email}");
        $this->line("Contraseña temporal: {$plainTextPassword}");

        return self::SUCCESS;
    }
}
