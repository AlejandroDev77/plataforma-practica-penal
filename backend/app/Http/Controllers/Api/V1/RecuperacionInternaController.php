<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\IndexarFuenteJuridica;
use App\Models\FuenteJuridica;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class RecuperacionInternaController extends Controller
{
    public function indexarFuenteJuridica(int $fuente): JsonResponse
    {
        $registro = FuenteJuridica::query()->findOrFail($fuente);
        $fechaVigencia = $registro->fecha_vigencia?->toDateString();
        $fechaFinVigencia = $registro->fecha_fin_vigencia?->toDateString();
        $hoy = today()->toDateString();

        if (! $registro->validada
            || $registro->estado !== 'vigente'
            || $fechaVigencia === null
            || $fechaVigencia > $hoy
            || ($fechaFinVigencia !== null && $fechaFinVigencia < $hoy)
            || trim((string) $registro->contenido_original) === '') {
            return response()->json([
                'error' => [
                    'code' => 'fuente_juridica_no_disponible',
                    'message' => 'Solo se indexan fuentes con contenido, validadas y actualmente vigentes.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        IndexarFuenteJuridica::dispatch((int) $registro->getKey());

        return response()->json([
            'data' => [
                'id_fuente' => $registro->getKey(),
                'estado' => 'encolado',
            ],
        ], Response::HTTP_ACCEPTED);
    }
}
