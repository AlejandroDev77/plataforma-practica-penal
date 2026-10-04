<?php

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
        //
    })->create();
