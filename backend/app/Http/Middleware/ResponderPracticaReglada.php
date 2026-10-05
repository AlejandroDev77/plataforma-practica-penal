<?php

namespace App\Http\Middleware;

use Closure;
use DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Frontera privada: ni APP_DEBUG ni errores SQL deben revelar el expediente. */
final class ResponderPracticaReglada
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }

    /** Laravel renderiza excepciones dentro de cada segmento del pipeline. */
    public static function render(\Throwable $error, Request $request): ?Response
    {
        if (! $request->is('api/v1/practicas-regladas/*')) {
            return null;
        }
        if ($error instanceof AuthenticationException) {
            $respuesta = response()->json(['message' => 'Inicie sesión para consultar la práctica.'], 401);
        } elseif ($error instanceof ModelNotFoundException) {
            $respuesta = response()->json(['message' => 'No encontramos ese recurso.'], 404);
        } elseif ($error instanceof ValidationException) {
            $respuesta = response()->json(['message' => 'Revise los campos enviados.', 'errors' => $error->errors()], 422);
        } elseif ($error instanceof DomainException) {
            $respuesta = response()->json(['message' => 'No se completó la acción. Consulte de nuevo la vigencia, el turno y las fuentes; revise también el proveedor y su autorización.'], 409);
        } elseif ($error instanceof QueryException) {
            $conflicto = in_array($error->errorInfo[0] ?? null, ['23514', '23505', '23503', '40001', '40P01', '55P03'], true);
            $respuesta = response()->json(['message' => $conflicto ? 'El contexto cambió. Consulte de nuevo antes de repetir la acción.' : 'El servicio de prácticas no está disponible.'], $conflicto ? 409 : 503);
        } elseif ($error instanceof HttpExceptionInterface) {
            $respuesta = response()->json(['message' => $error->getStatusCode() === 429 ? 'Espere antes de volver a solicitar esta acción.' : 'No se pudo completar la solicitud.'], $error->getStatusCode(), $error->getHeaders());
        } else {
            $respuesta = response()->json(['message' => 'El servicio de prácticas no está disponible.'], 503);
        }
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }
}
