<?php

$deploymentTier = env('SIGAL_DEPLOYMENT_TIER', env('APP_ENV', 'production'));
$resetRequested = (bool) env('SIGAL_OPERATIONAL_RESET_ENABLED', false);

return [
    /*
    |--------------------------------------------------------------------------
    | Identidad del despliegue
    |--------------------------------------------------------------------------
    |
    | APP_ENV continúa describiendo el modo técnico de Laravel. Este nivel
    | distingue la finalidad institucional de cada instalación: desarrollo,
    | pruebas, beta/UAT o producción definitiva.
    |
    */
    'deployment' => [
        'tier' => $deploymentTier,
        'label' => env('SIGAL_ENVIRONMENT_LABEL', strtoupper((string) $deploymentTier)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reinicio preoperativo
    |--------------------------------------------------------------------------
    |
    | La bandera por sí sola no basta. Aunque se configure por error en una
    | instalación productiva, la lista cerrada de niveles impide habilitarla.
    |
    */
    'operational_reset' => [
        'requested' => $resetRequested,
        'enabled' => $resetRequested && in_array($deploymentTier, ['local', 'testing', 'beta'], true),
    ],
];
