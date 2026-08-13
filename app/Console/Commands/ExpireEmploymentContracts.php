<?php

namespace App\Console\Commands;

use App\Domain\HumanResources\Services\HumanResourcesService;
use Illuminate\Console\Command;

class ExpireEmploymentContracts extends Command
{
    protected $signature = 'human-resources:expire-contracts';

    protected $description = 'Finaliza los contratos cuya fecha de fin ya venció.';

    public function handle(HumanResourcesService $humanResourcesService): int
    {
        $expired = $humanResourcesService->expireDueContracts();
        $this->info("Contratos finalizados: {$expired}");

        return self::SUCCESS;
    }
}
