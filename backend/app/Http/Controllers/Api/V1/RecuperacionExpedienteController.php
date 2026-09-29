<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BuscarRecuperacionExpedienteRequest;
use App\Jobs\IndexarArchivoExpediente;
use App\Models\Expediente;
use App\Services\Recuperacion\BuscarFragmentosExpediente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecuperacionExpedienteController extends Controller
{
    public function search(
        BuscarRecuperacionExpedienteRequest $request,
        int $expediente,
        BuscarFragmentosExpediente $buscador,
    ): JsonResponse {
        $registro = $this->expedientePropio($request, $expediente);
        $datos = $request->validated();
        $resultado = $buscador->ejecutar($registro, $datos['consulta'], $datos['limite'] ?? 5);

        return response()->json([
            'data' => $resultado['resultados'],
            'meta' => [
                'tipo_busqueda' => 'texto_completo_postgresql_espanol',
                'fragmentos_indexados' => $resultado['cantidad_fragmentos'],
                'estado_indice' => $resultado['estado_indice'],
                'relevancia_es_certeza' => false,
                'aviso' => 'La relevancia ordena coincidencias textuales; no es una valoración jurídica ni confirma un hecho.',
            ],
        ]);
    }

    public function indexar(Request $request, int $expediente): JsonResponse
    {
        $registro = $this->expedientePropio($request, $expediente);
        $archivos = $registro->archivos()
            ->whereIn('estado_procesamiento', ['procesado', 'error'])
            ->whereHas('paginas', fn ($consulta) => $consulta
                ->where('es_legible', true)
                ->whereRaw("btrim(texto_extraido) <> ''"))
            ->get(['id_archivo']);

        foreach ($archivos as $archivo) {
            IndexarArchivoExpediente::dispatch((int) $archivo->getKey());
        }

        return response()->json([
            'data' => [
                'archivos_en_cola' => $archivos->count(),
                'estado' => $archivos->isEmpty() ? 'sin_texto_legible' : 'encolado',
            ],
        ], Response::HTTP_ACCEPTED);
    }

    private function expedientePropio(Request $request, int $id): Expediente
    {
        return $request->user()->expedientes()->whereKey($id)->firstOrFail();
    }
}
