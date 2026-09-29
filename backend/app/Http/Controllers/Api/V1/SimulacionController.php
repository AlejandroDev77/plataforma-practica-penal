<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AvanzarSimulacionRequest;
use App\Http\Requests\Api\V1\BuscarRecuperacionExpedienteRequest;
use App\Http\Requests\Api\V1\CrearSimulacionRequest;
use App\Http\Requests\Api\V1\RegistrarIntervencionRequest;
use App\Http\Resources\Api\V1\SimulacionResource;
use App\Models\Simulacion;
use App\Services\Recuperacion\BuscarFuentesSimulacion;
use App\Services\Simulaciones\AvanzarEtapaAudiencia;
use App\Services\Simulaciones\CrearSimulacion;
use App\Services\Simulaciones\RegistrarIntervencion;
use App\Services\Simulaciones\ResolverTurnoAudiencia;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SimulacionController extends Controller
{
    public function index(
        Request $request,
        int $expediente,
        ResolverTurnoAudiencia $resolverTurnos,
    ): JsonResponse {
        $caso = $request->user()->expedientes()->whereKey($expediente)->firstOrFail();
        $simulaciones = $caso->simulaciones()
            ->with([
                'tipoAudiencia',
                'etapaActual.transicionesSalientes' => fn ($query) => $query
                    ->where('activo', true)
                    ->whereHas('destino', fn ($destino) => $destino->where('activo', true)),
                'etapaActual.transicionesSalientes.destino',
            ])
            ->withCount(['intervenciones as intervenciones_etapa_actual_count' => fn ($query) => $query->whereColumn('intervenciones.id_etapa', 'simulaciones.id_etapa_actual')])
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id_simulacion')
            ->paginate(20);

        $simulaciones->getCollection()->each(function (Simulacion $simulacion) use ($resolverTurnos): void {
            $simulacion->setAttribute(
                'turno_actual',
                $resolverTurnos->resumir($simulacion, (int) $simulacion->intervenciones_etapa_actual_count),
            );
        });

        return SimulacionResource::collection($simulaciones)->response();
    }

    public function store(
        CrearSimulacionRequest $request,
        int $expediente,
        CrearSimulacion $crear,
        ResolverTurnoAudiencia $resolverTurnos,
    ): JsonResponse {
        $caso = $request->user()->expedientes()->whereKey($expediente)->firstOrFail();
        $datos = $request->validated();
        $simulacion = $crear->ejecutar(
            $request->user(),
            $caso,
            (int) $datos['id_analisis'],
            (int) $datos['id_tipo_audiencia'],
        );

        return SimulacionResource::make($this->cargarDetalles($simulacion, $resolverTurnos))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        int $simulacion,
        ResolverTurnoAudiencia $resolverTurnos,
    ): JsonResponse {
        $registro = $request->user()->simulaciones()->whereKey($simulacion)->firstOrFail();

        return SimulacionResource::make($this->cargarDetalles($registro, $resolverTurnos))->response();
    }

    public function fuentes(
        BuscarRecuperacionExpedienteRequest $request,
        int $simulacion,
        BuscarFuentesSimulacion $buscarFuentes,
    ): JsonResponse {
        $registro = $request->user()->simulaciones()->whereKey($simulacion)->firstOrFail();
        $datos = $request->validated();
        $resultado = $buscarFuentes->ejecutar(
            $registro,
            $datos['consulta'],
            $datos['limite'] ?? 5,
        );

        return response()->json([
            'data' => [
                'expediente' => $resultado['expediente']['resultados'],
                'juridica' => $resultado['juridica']['resultados'],
            ],
            'meta' => [
                'tipo_busqueda' => 'texto_completo_postgresql_espanol',
                'estado_indice_expediente' => $resultado['expediente']['estado_indice'],
                'fragmentos_expediente_indexados' => $resultado['expediente']['cantidad_fragmentos'],
                'fuentes_juridicas_vigentes_indexadas' => $resultado['juridica']['cantidad_fuentes'],
                'fragmentos_juridicos_disponibles' => $resultado['juridica']['cantidad_fragmentos'],
                'relevancia_es_certeza' => false,
                'aviso' => 'Las coincidencias son candidatas para consulta. La relevancia ordena texto y no confirma hechos ni interpretación jurídica.',
            ],
        ]);
    }

    public function storeIntervencion(
        RegistrarIntervencionRequest $request,
        int $simulacion,
        RegistrarIntervencion $registrar,
        ResolverTurnoAudiencia $resolverTurnos,
    ): JsonResponse {
        $registro = $request->user()->simulaciones()->whereKey($simulacion)->firstOrFail();
        $actualizada = $registrar->ejecutar(
            $registro,
            $request->validated('contenido'),
            $request->validated('source_fragment_ids', []),
        );

        return SimulacionResource::make($this->cargarDetalles($actualizada, $resolverTurnos))
            ->response()
            ->setStatusCode(201);
    }

    public function avanzar(
        AvanzarSimulacionRequest $request,
        int $simulacion,
        AvanzarEtapaAudiencia $avanzar,
        ResolverTurnoAudiencia $resolverTurnos,
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

        return SimulacionResource::make($this->cargarDetalles($actualizada, $resolverTurnos))->response();
    }

    private function cargarDetalles(Simulacion $simulacion, ResolverTurnoAudiencia $resolverTurnos): Simulacion
    {
        $simulacion->load([
            'tipoAudiencia',
            'etapaActual.transicionesSalientes' => fn ($query) => $query
                ->where('activo', true)
                ->whereHas('destino', fn ($destino) => $destino->where('activo', true)),
            'etapaActual.transicionesSalientes.destino',
            'participantes',
            'intervenciones.participante',
            'intervenciones.fuentes',
        ]);

        $simulacion->loadCount(['intervenciones as intervenciones_etapa_actual_count' => fn ($query) => $query->whereColumn('intervenciones.id_etapa', 'simulaciones.id_etapa_actual')]);
        $simulacion->setAttribute(
            'turno_actual',
            $resolverTurnos->resumir($simulacion, (int) $simulacion->intervenciones_etapa_actual_count),
        );

        return $simulacion;
    }
}
