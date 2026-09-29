<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BuscarRecuperacionExpedienteRequest;
use App\Services\Recuperacion\BuscarFragmentosJuridicos;
use Illuminate\Http\JsonResponse;

final class RecuperacionJuridicaController extends Controller
{
    public function search(
        BuscarRecuperacionExpedienteRequest $request,
        BuscarFragmentosJuridicos $buscador,
    ): JsonResponse {
        $datos = $request->validated();
        $resultado = $buscador->ejecutar($datos['consulta'], $datos['limite'] ?? 5);

        return response()->json([
            'data' => $resultado['resultados'],
            'meta' => [
                'tipo_busqueda' => 'texto_completo_postgresql_espanol',
                'fuentes_vigentes_indexadas' => $resultado['cantidad_fuentes'],
                'fragmentos_disponibles' => $resultado['cantidad_fragmentos'],
                'relevancia_es_certeza' => false,
                'aviso' => 'Solo se consultan fuentes marcadas como validadas y vigentes. La relevancia no confirma interpretación ni vigencia jurídica fuera de esos metadatos.',
            ],
        ]);
    }
}
