<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RevisarAnalisisRequest;
use App\Http\Requests\Api\V1\SolicitarAnalisisRequest;
use App\Http\Resources\Api\V1\AnalisisExpedienteResource;
use App\Jobs\GenerarAnalisisExpediente;
use App\Models\Expediente;
use App\Models\HistorialProcesamiento;
use App\Models\PaginaExpediente;
use App\Services\Analisis\RegistrarRevisionAnalisis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function store(SolicitarAnalisisRequest $request, int $expediente): JsonResponse
    {
        $caso = $this->expedientePropio($request, $expediente);
        $pageIds = $request->validated()['page_ids'];
        $paginas = PaginaExpediente::query()
            ->whereIn('id_pagina', $pageIds)
            ->whereHas('archivo', fn ($query) => $query->where('id_expediente', $caso->getKey()))
            ->orderBy('id_archivo')
            ->orderBy('numero_pagina')
            ->get();

        abort_unless($paginas->count() === count($pageIds), Response::HTTP_NOT_FOUND);

        $caracteres = 0;
        foreach ($paginas as $pagina) {
            if (! $pagina->es_legible || ! is_string($pagina->texto_extraido) || trim($pagina->texto_extraido) === '') {
                throw ValidationException::withMessages([
                    'page_ids' => 'Selecciona únicamente páginas que tengan texto legible.',
                ]);
            }

            $caracteres += mb_strlen($pagina->texto_extraido, 'UTF-8');
        }

        if ($caracteres > 40_000) {
            throw ValidationException::withMessages([
                'page_ids' => 'Las páginas seleccionadas superan el límite de 40.000 caracteres.',
            ]);
        }

        $proceso = DB::transaction(function () use ($caso, $pageIds): HistorialProcesamiento {
            $casoBloqueado = Expediente::query()->whereKey($caso->getKey())->lockForUpdate()->firstOrFail();
            $enCurso = $casoBloqueado->procesamientos()
                ->where('tipo', 'analisis_estructurado')
                ->whereIn('estado', ['pendiente', 'procesando'])
                ->exists();

            abort_if($enCurso, Response::HTTP_CONFLICT, 'Ya hay un análisis en curso para este expediente.');

            return $casoBloqueado->procesamientos()->create([
                'tipo' => 'analisis_estructurado',
                'estado' => 'pendiente',
                'intento' => 1,
                'metadatos' => ['page_ids' => array_values($pageIds)],
            ]);
        });

        GenerarAnalisisExpediente::dispatch($proceso->getKey())->afterCommit();

        return response()->json([
            'data' => [
                'process_id' => $proceso->getKey(),
                'status' => $proceso->estado,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    public function processStatus(Request $request, int $expediente, int $proceso): JsonResponse
    {
        $registro = $this->expedientePropio($request, $expediente)
            ->procesamientos()
            ->where('tipo', 'analisis_estructurado')
            ->whereKey($proceso)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'process_id' => $registro->getKey(),
                'status' => $registro->estado,
                'message' => $registro->mensaje_error,
                'analysis_id' => $registro->id_analisis,
                'started_at' => $registro->fecha_inicio?->toISOString(),
                'finished_at' => $registro->fecha_fin?->toISOString(),
            ],
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
