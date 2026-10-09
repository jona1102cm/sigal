<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#103B35">
        <title>SIGAL · Asamblea Legislativa Departamental del Beni</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body @class(['is-uat-environment' => config('sigal.uat.enabled')])>
        @if (config('sigal.uat.enabled'))
            <div class="uat-global-banner" role="status">
                <strong>{{ config('sigal.uat.label') }}</strong>
                <span>Entorno beta: toda operación generada aquí es exclusivamente de prueba.</span>
            </div>
        @endif
        <div id="legislatures-app">
            <div id="sigal-app"></div>
        </div>
    </body>
</html>
