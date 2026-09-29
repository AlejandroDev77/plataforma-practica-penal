<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GuardarPropuestaTurnoRequest;
use App\Models\PropuestaTurnoAudiencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PropuestaTurnoAudienciaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->exigirAdministrador($request);

        $filtros = $request->validate([
            'id_tipo_audiencia' => ['sometimes', 'integer', 'min:1'],
            'id_etapa' => ['sometimes', 'integer', 'min:1'],
            'buscar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $consulta = PropuestaTurnoAudiencia::query()
            ->with(['tipoAudiencia', 'etapa', 'creador'])
            ->when(isset($filtros['id_tipo_audiencia']), fn ($query) => $query->where('id_tipo_audiencia', $filtros['id_tipo_audiencia']))
            ->when(isset($filtros['id_etapa']), fn ($query) => $query->where('id_etapa', $filtros['id_etapa']))
            ->when(trim($filtros['buscar'] ?? '') !== '', function ($query) use ($filtros): void {
                $termino = trim($filtros['buscar']);
                $query->where(function ($consulta) use ($termino): void {
                    $consulta->where('acto_propuesto', 'ilike', '%'.$termino.'%')
                        ->orWhere('descripcion_propuesta', 'ilike', '%'.$termino.'%')
                        ->orWhere('referencia_normativa_propuesta', 'ilike', '%'.$termino.'%');
                });
            })
            ->orderBy('id_tipo_audiencia')
            ->orderBy('id_etapa')
            ->orderBy('orden');

        $pagina = $consulta->paginate(20);

        return response()->json([
            'data' => $pagina->getCollection()
                ->map(fn (PropuestaTurnoAudiencia $propuesta): array => $this->representar($propuesta))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ]);
    }

    public function store(GuardarPropuestaTurnoRequest $request): JsonResponse
    {
        $propuesta = new PropuestaTurnoAudiencia($request->safe()->only([
            'id_tipo_audiencia',
            'id_etapa',
            'orden',
            'rol',
            'acto_propuesto',
            'descripcion_propuesta',
            'referencia_normativa_propuesta',
            'observaciones',
        ]));
        $propuesta->id_usuario_creador = $request->user()->getKey();
        $propuesta->estado = 'borrador';
        $propuesta->save();

        return response()->json([
            'data' => $this->representar($propuesta->load(['tipoAudiencia', 'etapa', 'creador'])),
        ], Response::HTTP_CREATED);
    }

    public function update(GuardarPropuestaTurnoRequest $request, int $propuesta): JsonResponse
    {
        $registro = PropuestaTurnoAudiencia::query()->findOrFail($propuesta);
        $registro->fill($request->safe()->only([
            'id_tipo_audiencia',
            'id_etapa',
            'orden',
            'rol',
            'acto_propuesto',
            'descripcion_propuesta',
            'referencia_normativa_propuesta',
            'observaciones',
        ]))->save();

        return response()->json([
            'data' => $this->representar($registro->refresh()->load(['tipoAudiencia', 'etapa', 'creador'])),
        ]);
    }

    public function destroy(Request $request, int $propuesta): Response
    {
        $this->exigirAdministrador($request);

        PropuestaTurnoAudiencia::query()->findOrFail($propuesta)->delete();

        return response()->noContent();
    }

    private function exigirAdministrador(Request $request): void
    {
        abort_unless($request->user()?->hasRole('administrador_plataforma'), Response::HTTP_FORBIDDEN);
    }

    /** @return array<string, mixed> */
    private function representar(PropuestaTurnoAudiencia $propuesta): array
    {
        return [
            'id' => $propuesta->getKey(),
            'id_tipo_audiencia' => $propuesta->id_tipo_audiencia,
            'tipo_audiencia' => $propuesta->tipoAudiencia?->nombre,
            'id_etapa' => $propuesta->id_etapa,
            'etapa' => $propuesta->etapa?->nombre,
            'orden' => $propuesta->orden,
            'rol' => $propuesta->rol,
            'acto_propuesto' => $propuesta->acto_propuesto,
            'descripcion_propuesta' => $propuesta->descripcion_propuesta,
            'referencia_normativa_propuesta' => $propuesta->referencia_normativa_propuesta,
            'observaciones' => $propuesta->observaciones,
            'estado' => 'borrador',
            'creador' => $propuesta->creador?->name ?? 'Cuenta eliminada',
            'fecha_actualizacion' => ($propuesta->fecha_actualizacion ?? $propuesta->fecha_creacion)?->toIso8601String(),
        ];
    }
}
