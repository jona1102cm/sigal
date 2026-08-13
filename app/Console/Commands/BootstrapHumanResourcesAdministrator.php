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
use Illuminate\Validation\ValidationException;

class BootstrapHumanResourcesAdministrator extends Command
{
    protected $signature = 'sigal:bootstrap-human-resources-administrator
        {--name= : Nombre de la cuenta institucional de Recursos Humanos}
        {--email= : Correo institucional de la cuenta}';

    protected $description = 'Crea la cuenta institucional independiente para administrar Recursos Humanos.';

    public function handle(ActivityLogger $activityLogger): int
    {
        $name = $this->option('name') ?: 'Administración de Recursos Humanos';
        $email = $this->option('email') ?: 'rrhh@sigal.local';
        $plainTextPassword = Str::password(24, symbols: true);

        try {
            [$user, $wasCreated] = DB::transaction(function () use ($name, $email, $plainTextPassword, $activityLogger): array {
                $role = Role::query()->firstOrCreate([
                    'code' => RoleCode::HumanResourcesManager->value,
                ], [
                    'name' => RoleCode::HumanResourcesManager->label(),
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

                if ($user->employee_id !== null) {
                    throw ValidationException::withMessages([
                        'email' => 'La cuenta institucional de Recursos Humanos no puede estar vinculada a un funcionario.',
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
                    event: 'authorization.human_resources_administrator_bootstrapped',
                    actor: null,
                    subject: $user,
                    context: new RequestAuditContext(null, 'artisan'),
                    newValues: [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'role' => RoleCode::HumanResourcesManager->value,
                        'user_created' => $wasCreated,
                        'role_assignment_id' => $assignment->id,
                    ],
                );

                return [$user, $wasCreated];
            });
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $wasCreated) {
            $this->warn("La cuenta {$user->email} ya existía; no se modificó su contraseña.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Cuenta institucional de Recursos Humanos creada. Guarde la contraseña y cámbiela después del primer acceso.');
        $this->line("Correo: {$user->email}");
        $this->line("Contraseña temporal: {$plainTextPassword}");

        return self::SUCCESS;
    }
}
