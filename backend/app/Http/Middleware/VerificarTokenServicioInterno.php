<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerificarTokenServicioInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.intelligence.token', '');
        $provided = (string) $request->bearerToken();

        if ($expected === '') {
            return response()->json([
                'error' => [
                    'code' => 'servicio_interno_no_configurado',
                    'message' => 'La integración interna no está configurada.',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'error' => [
                    'code' => 'credencial_interna_no_valida',
                    'message' => 'La credencial del servicio no es válida.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
