<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Evaluaciones\PrepararContextoEvaluacionReglada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ContextoEvaluacionRegladaController extends Controller
{
    public function __invoke(Request $request, int $practica, PrepararContextoEvaluacionReglada $preparar): JsonResponse
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['solicitud' => 'La consulta no admite campos adicionales.']);
        }

        return response()->json(['data' => $preparar->ejecutar($request->user(), $practica)]);
    }
}
