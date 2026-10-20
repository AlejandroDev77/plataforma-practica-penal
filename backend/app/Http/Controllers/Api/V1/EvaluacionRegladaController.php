<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EvaluacionResource;
use App\Jobs\EvaluarSimulacion;
use App\Models\Simulacion;
use App\Services\Evaluaciones\SolicitarEvaluacionReglada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class EvaluacionRegladaController extends Controller
{
    public function latest(Request $request, int $practica): JsonResponse
    {
        $fila = $request->user()->simulaciones()->whereKey($practica)->firstOrFail()
            ->evaluaciones()->whereIn('id_evaluacion', DB::table('contextos_evaluacion_reglada')
                ->join('evaluaciones as evaluaciones_contexto', 'evaluaciones_contexto.id_evaluacion', '=', 'contextos_evaluacion_reglada.id_evaluacion')
                ->where('evaluaciones_contexto.id_simulacion', $practica)->select('contextos_evaluacion_reglada.id_evaluacion'))
            ->with(['rubrica', 'resultados.criterio'])->orderByDesc('fecha_creacion')
            ->orderByDesc('id_evaluacion')->first();

        return response()->json(['data' => $fila === null ? null : EvaluacionResource::make($fila)->resolve($request)]);
    }

    public function store(Request $request, int $practica, SolicitarEvaluacionReglada $solicitar): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['confirmar_envio', 'huella_contexto']) !== []) {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud contiene campos no admitidos.']);
        }
        $confirmacion = $request->input('confirmar_envio');
        $huella = $request->input('huella_contexto');
        if ($confirmacion !== true || ! is_string($huella)) {
            throw ValidationException::withMessages(['consentimiento' => 'Confirme el envío del contexto exacto a OpenAI.']);
        }
        $evaluacion = $solicitar->ejecutar($request->user(), $practica, $huella, $confirmacion);
        EvaluarSimulacion::dispatch($evaluacion->getKey(), 1)->afterCommit();

        return EvaluacionResource::make($evaluacion->load('rubrica'))
            ->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, int $practica, int $evaluacion): JsonResponse
    {
        $fila = $request->user()->simulaciones()->whereKey($practica)->firstOrFail()
            ->evaluaciones()->with(['rubrica', 'resultados.criterio'])->whereKey($evaluacion)->firstOrFail();
        abort_unless(DB::table('contextos_evaluacion_reglada')->where('id_evaluacion', $fila->getKey())->exists(), 404);

        return EvaluacionResource::make($fila)->response();
    }
}
