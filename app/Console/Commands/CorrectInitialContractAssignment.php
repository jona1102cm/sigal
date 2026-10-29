<?php

namespace App\Console\Commands;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\HumanResources\DTOs\CorrectInitialContractAssignmentData;
use App\Domain\HumanResources\Services\HumanResourcesService;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\EmploymentContract;
use App\Models\Office;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Corrige una asignación inicial equivocada sin registrar una rotación inexistente. */
class CorrectInitialContractAssignment extends Command
{
    protected $signature = 'sigal:correct-initial-contract-assignment
        {contract : ID del contrato vigente que fue cargado incorrectamente}
        {office : Código de la oficina correcta}
        {--role=official : Función organizacional correcta: official o manager}
        {--actor= : ID del superadministrador o responsable de RR. HH. que autoriza}
        {--reason= : Motivo obligatorio de la corrección}';

    protected $description = 'Corrige de forma auditable la oficina y función iniciales de un contrato sin crear una rotación.';

    public function handle(HumanResourcesService $humanResources): int
    {
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        $reason = trim((string) $this->option('reason'));
        $role = OfficeMembershipRole::tryFrom((string) $this->option('role'));

        if ($actorId === false || $actorId === null || $reason === '' || $role === null) {
            $this->error('Debe indicar --actor, --reason y un --role válido (official o manager).');

            return self::INVALID;
        }

        $actor = User::query()->find($actorId);
        if ($actor === null || ! $actor->hasPermission(PermissionCode::HumanResourcesContracts)) {
            $this->error('El actor indicado no existe, está inactivo o no administra contratos de Recursos Humanos.');

            return self::FAILURE;
        }

        $contract = EmploymentContract::query()->findOrFail((int) $this->argument('contract'));
        $office = Office::query()->where('code', mb_strtoupper(trim((string) $this->argument('office'))))->firstOrFail();

        try {
            $corrected = $humanResources->correctInitialContractAssignment(
                $contract,
                new CorrectInitialContractAssignmentData(
                    targetOfficeId: (int) $office->id,
                    membershipRole: $role,
                    reason: $reason,
                ),
                $actor,
                new RequestAuditContext(null, 'artisan:sigal:correct-initial-contract-assignment'),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $membership = $corrected->currentOfficeMembership;
        $this->info('Asignación inicial corregida y auditada.');
        $this->line("Contrato: {$corrected->id}");
        $this->line("Oficina: {$membership->office->code} - {$membership->office->name}");
        $this->line("Cargo: {$membership->position_title}");
        $this->line("Función: {$membership->membership_role->value}");

        return self::SUCCESS;
    }
}
