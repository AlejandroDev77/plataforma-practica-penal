<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Evaluaciones\ConsultarPreparacionEvaluacionReglada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PreparacionEvaluacionRegladaController extends Controller
{
    public function __invoke(Request $request, int $practica, ConsultarPreparacionEvaluacionReglada $consulta): JsonResponse
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['solicitud' => 'Esta consulta no admite campos adicionales.']);
        }

        return response()->json(['data' => $consulta->ejecutar($request->user(), $practica)]);
    }
}
