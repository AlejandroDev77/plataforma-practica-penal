<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Simulaciones\OrquestadorAudiencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrquestacionAudienciaController extends Controller
{
    public function __invoke(Request $request, int $simulacion, OrquestadorAudiencia $orquestador): JsonResponse
    {
        return response()->json(['data' => $orquestador->consultar($request->user(), $simulacion)]);
    }
}
