<?php

namespace App\Console\Commands;

use App\Domain\HumanResources\Services\HumanResourcesService;
use Illuminate\Console\Command;

/** Ejecuta desde consola la activación idempotente de contratos cuya vigencia comienza. */
class ActivateStartingContracts extends Command
{
    protected $signature = 'human-resources:activate-starting-contracts';

    protected $description = 'Activa las cuentas cuyos contratos inician hoy.';

    public function handle(HumanResourcesService $humanResourcesService): int
    {
        $activated = $humanResourcesService->activateStartingContracts();
        $this->info("Cuentas activadas: {$activated}");

        return self::SUCCESS;
    }
}
