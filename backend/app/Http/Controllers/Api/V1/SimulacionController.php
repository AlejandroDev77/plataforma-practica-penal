<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AvanzarSimulacionRequest;
use App\Http\Requests\Api\V1\CrearSimulacionRequest;
use App\Http\Resources\Api\V1\SimulacionResource;
use App\Models\Simulacion;
use App\Services\Simulaciones\AvanzarEtapaAudiencia;
use App\Services\Simulaciones\CrearSimulacion;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SimulacionController extends Controller
{
    public function index(Request $request, int $expediente): JsonResponse
    {
        $caso = $request->user()->expedientes()->whereKey($expediente)->firstOrFail();
        $simulaciones = $caso->simulaciones()
            ->with([
                'tipoAudiencia',
                'etapaActual.transicionesSalientes' => fn ($query) => $query
                    ->where('activo', true)
                    ->whereHas('destino', fn ($destino) => $destino->where('activo', true)),
                'etapaActual.transicionesSalientes.destino',
            ])
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id_simulacion')
            ->paginate(20);

        return SimulacionResource::collection($simulaciones)->response();
    }

    public function store(
        CrearSimulacionRequest $request,
        int $expediente,
        CrearSimulacion $crear,
    ): JsonResponse {
        $caso = $request->user()->expedientes()->whereKey($expediente)->firstOrFail();
        $datos = $request->validated();
        $simulacion = $crear->ejecutar(
            $request->user(),
            $caso,
            (int) $datos['id_analisis'],
            (int) $datos['id_tipo_audiencia'],
        );

        return SimulacionResource::make($this->cargarDetalles($simulacion))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $simulacion): JsonResponse
    {
        $registro = $request->user()->simulaciones()->whereKey($simulacion)->firstOrFail();

        return SimulacionResource::make($this->cargarDetalles($registro))->response();
    }

    public function avanzar(
        AvanzarSimulacionRequest $request,
        int $simulacion,
        AvanzarEtapaAudiencia $avanzar,
    ): JsonResponse {
        $registro = $request->user()->simulaciones()->whereKey($simulacion)->firstOrFail();
        $destino = $request->validated('id_etapa_destino');

        try {
            $actualizada = $avanzar->ejecutar($registro, $destino === null ? null : (int) $destino);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'id_etapa_destino' => $exception->getMessage(),
            ]);
        }

        return SimulacionResource::make($this->cargarDetalles($actualizada))->response();
    }

    private function cargarDetalles(Simulacion $simulacion): Simulacion
    {
        return $simulacion->load([
            'tipoAudiencia',
            'etapaActual.transicionesSalientes' => fn ($query) => $query
                ->where('activo', true)
                ->whereHas('destino', fn ($destino) => $destino->where('activo', true)),
            'etapaActual.transicionesSalientes.destino',
            'participantes',
            'intervenciones.participante',
        ]);
    }
}
