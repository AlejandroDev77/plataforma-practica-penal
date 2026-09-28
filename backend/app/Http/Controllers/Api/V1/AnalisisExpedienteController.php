<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RevisarAnalisisRequest;
use App\Http\Resources\Api\V1\AnalisisExpedienteResource;
use App\Models\Expediente;
use App\Services\Analisis\RegistrarRevisionAnalisis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AnalisisExpedienteController extends Controller
{
    public function index(Request $request, int $expediente): JsonResponse
    {
        $caso = $this->expedientePropio($request, $expediente);
        $analisis = $caso->analisis()
            ->with(['revisiones.usuario'])
            ->orderByDesc('version')
            ->first();

        return response()->json([
            'data' => $analisis === null ? null : (new AnalisisExpedienteResource($analisis))->resolve($request),
        ]);
    }

    public function storeRevision(
        RevisarAnalisisRequest $request,
        int $expediente,
        int $analisis,
        RegistrarRevisionAnalisis $registrar,
    ): JsonResponse {
        $caso = $this->expedientePropio($request, $expediente);
        $registro = $caso->analisis()->whereKey($analisis)->firstOrFail();
        $datos = $request->validated();
        $revision = $registrar->ejecutar($registro, $request->user(), $datos['decision'], $datos['observacion'] ?? null);
        $registro->load('revisiones.usuario');

        return response()->json([
            'data' => (new AnalisisExpedienteResource($registro))->resolve($request),
            'review_id' => $revision->getKey(),
        ], Response::HTTP_CREATED);
    }

    private function expedientePropio(Request $request, int $id): Expediente
    {
        return $request->user()->expedientes()->whereKey($id)->firstOrFail();
    }
}
