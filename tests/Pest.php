<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuración común de Pest
|--------------------------------------------------------------------------
|
| Los escenarios Feature necesitan el contenedor de Laravel, por eso se
| vinculan a Tests\TestCase. Las pruebas unitarias puras conservan el caso
| base de Pest y no arrancan la aplicación innecesariamente.
|
*/

pest()->extend(TestCase::class)->in('Pest/Feature');
