<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GuardarAnalisisEstructuradoRequest;
use App\Models\Expediente;
use App\Services\Analisis\PersistirAnalisisExpediente;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AnalisisInternoController extends Controller
{
    public function store(GuardarAnalisisEstructuradoRequest $request, int $expediente, PersistirAnalisisExpediente $persistir): JsonResponse
    {
        $registro = Expediente::query()->findOrFail($expediente);
        $analisis = $persistir->ejecutar($registro, $request->validated()['result']);

        return response()->json([
            'data' => [
                'id' => $analisis->getKey(),
                'case_id' => $analisis->id_expediente,
                'version' => $analisis->version,
                'status' => $analisis->estado,
            ],
        ], Response::HTTP_CREATED);
    }
}
