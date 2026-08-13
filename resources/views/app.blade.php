<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#103B35">
        <title>SIGAL · Asamblea Legislativa Departamental del Beni</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="legislatures-app">
            <div id="sigal-app"></div>
        </div>
    </body>
</html>
