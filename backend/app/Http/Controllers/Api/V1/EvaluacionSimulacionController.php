<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SolicitarEvaluacionRequest;
use App\Http\Resources\Api\V1\EvaluacionResource;
use App\Jobs\EvaluarSimulacion;
use App\Services\Evaluaciones\SolicitarEvaluacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EvaluacionSimulacionController extends Controller
{
    public function latest(Request $request, int $simulacion): JsonResponse
    {
        $registro = $request->user()->simulaciones()
            ->whereKey($simulacion)
            ->firstOrFail()
            ->evaluaciones()
            ->with(['rubrica', 'resultados.criterio'])
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id_evaluacion')
            ->first();

        return response()->json([
            'data' => $registro === null ? null : EvaluacionResource::make($registro)->resolve($request),
        ]);
    }

    public function store(
        SolicitarEvaluacionRequest $request,
        int $simulacion,
        SolicitarEvaluacion $solicitar,
    ): JsonResponse {
        $registro = $request->user()->simulaciones()->whereKey($simulacion)->firstOrFail();
        $evaluacion = $solicitar->ejecutar($registro);
        EvaluarSimulacion::dispatch($evaluacion->getKey())->afterCommit();

        return EvaluacionResource::make($evaluacion->load('rubrica'))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, int $simulacion, int $evaluacion): JsonResponse
    {
        $registro = $request->user()->simulaciones()
            ->whereKey($simulacion)
            ->firstOrFail()
            ->evaluaciones()
            ->with(['rubrica', 'resultados.criterio'])
            ->whereKey($evaluacion)
            ->firstOrFail();

        return EvaluacionResource::make($registro)->response();
    }
}
