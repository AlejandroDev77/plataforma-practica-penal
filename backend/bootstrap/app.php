<?php

use App\Http\Middleware\ResponderPracticaReglada;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        // Texto explícito que debe conservar sus espacios y saltos al sellarse.
        $middleware->trimStrings(except: ['instrucciones.*.texto']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(ResponderPracticaReglada::render(...));
        // Los parámetros SQL pueden contener documentos; no registrarlos en errores HTTP.
        // La evidencia de comandos confirmados conserva su propio registro inmutable.
        $exceptions->dontReportWhen(fn (Throwable $error) => request()->is('api/v1/practicas-regladas/*'));
    })->create();
