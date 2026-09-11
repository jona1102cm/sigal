<?php

use App\Http\Middleware\EnsureActiveSystemUser;
use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\PreventApiResponseCaching;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    // Centraliza las superficies HTTP, los comandos y el endpoint liviano de salud.
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias descriptivos utilizados por routes/api.php para las restricciones de sesión.
        $middleware->alias([
            'active.user' => EnsureActiveSystemUser::class,
            'no.store' => PreventApiResponseCaching::class,
            'password.changed' => EnsurePasswordHasBeenChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // La API siempre responde JSON, incluso cuando Laravel produce una excepción antes del controlador.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
