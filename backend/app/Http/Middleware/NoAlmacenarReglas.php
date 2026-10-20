<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NoAlmacenarReglas
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }
}
