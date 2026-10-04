<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HabilitacionRevisionJuridicaResource;
use App\Http\Resources\Api\V1\RevisionReglasAudienciaResource;
use App\Http\Resources\Api\V1\VersionReglasAudienciaResource;
use App\Models\HabilitacionRevisionJuridica;
use App\Models\RevisionReglasAudiencia;
use App\Models\User;
use App\Models\VersionReglasAudiencia;
use App\Services\Simulaciones\GestionarRevisionJuridicaReglas;
use App\Services\Simulaciones\SellarVersionReglasAudiencia;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GobiernoReglasAudienciaController extends Controller
{
    // Capturar antes del pipeline de Laravel: no exponer ni reportar SQL/bindings
    // con acreditaciones cuando APP_DEBUG esta habilitado en desarrollo.
    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (DomainException $error) {
            return response()->json([
                'code' => 'reglas_conflicto',
                'message' => 'No se pudo registrar el cambio. Consulte de nuevo y revise independencia, vigencia y estado.',
            ], 409);
        } catch (QueryException $error) {
            $conflicto = in_array($error->errorInfo[0] ?? null, ['23503', '23505', '23514', '42501', '40001', '40P01'], true);

            return response()->json([
                'code' => $conflicto ? 'reglas_conflicto' : 'reglas_no_disponibles',
                'message' => $conflicto ? 'El estado cambio o la operacion no esta permitida. Consulte de nuevo.'
                    : 'No se pudo consultar o registrar las reglas en este momento.',
            ], $conflicto ? 409 : 503);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $this->filtros($request);

        return $this->pagina($request, $this->versionesVisibles($request)
            ->when(isset($filtros['id_tipo_audiencia']), fn ($q) => $q->where('id_tipo_audiencia', $filtros['id_tipo_audiencia']))
            ->orderByDesc('id_version_reglas'), VersionReglasAudienciaResource::class, $filtros);
    }

    public function show(Request $request, int $version): JsonResponse
    {
        $registro = $this->versionesVisibles($request)->findOrFail($version);

        return response()->json(['data' => (new VersionReglasAudienciaResource($registro))->resolve($request)]);
    }

    public function storeVersion(Request $request, int $tipo, SellarVersionReglasAudiencia $sellador): JsonResponse
    {
        abort_unless($this->esAdministrador($request), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $registro = $sellador->ejecutar($request->user(), $tipo, $datos['motivo']);

        return response()->json(['data' => (new VersionReglasAudienciaResource($registro))->resolve($request)], 201);
    }

    public function indexHabilitaciones(Request $request): JsonResponse
    {
        $filtros = $this->filtros($request);

        return $this->pagina($request, $this->habilitacionesVisibles($request)
            ->when(isset($filtros['id_tipo_audiencia']), fn ($q) => $q->where('id_tipo_audiencia', $filtros['id_tipo_audiencia']))
            ->orderByDesc('id_habilitacion'), HabilitacionRevisionJuridicaResource::class, $filtros);
    }

    public function showHabilitacion(Request $request, int $habilitacion): JsonResponse
    {
        $registro = $this->habilitacionesVisibles($request)->findOrFail($habilitacion);

        return response()->json(['data' => (new HabilitacionRevisionJuridicaResource($registro))->resolve($request)]);
    }

    public function storeHabilitacion(Request $request, GestionarRevisionJuridicaReglas $gestor): JsonResponse
    {
        abort_unless($this->esAdministrador($request), 403);
        $ids = $request->validate([
            'id_usuario_revisor' => ['required', 'integer', 'min:1'],
            'id_tipo_audiencia' => ['required', 'integer', 'min:1'],
        ]);
        $registro = $gestor->habilitar($request->user(), (int) $ids['id_usuario_revisor'], (int) $ids['id_tipo_audiencia'],
            $request->only(['alcance', 'referencia_acreditacion', 'huella_acreditacion', 'acreditacion_verificada', 'fecha_vencimiento']));

        return response()->json(['data' => (new HabilitacionRevisionJuridicaResource($registro))->resolve($request)], 201);
    }

    public function revokeHabilitacion(Request $request, int $habilitacion, GestionarRevisionJuridicaReglas $gestor): JsonResponse
    {
        $registro = $this->habilitacionesVisibles($request)->findOrFail($habilitacion);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $gestor->revocarHabilitacion($request->user(), $registro->getKey(), $datos['motivo']);

        // Reintentos autorizados devuelven el mismo registro; no reemplazan su motivo.
        return response()->json(['data' => (new HabilitacionRevisionJuridicaResource($registro))->resolve($request)]);
    }

    public function indexRevisiones(Request $request, int $version): JsonResponse
    {
        $registro = $this->versionesVisibles($request)->findOrFail($version);
        $filtros = $this->filtros($request);

        return $this->pagina($request, RevisionReglasAudiencia::query()->where('id_version_reglas', $registro->getKey())
            ->orderByDesc('fecha_creacion')->orderByDesc('id_revision_reglas'), RevisionReglasAudienciaResource::class, $filtros);
    }

    public function storeRevision(Request $request, int $version, GestionarRevisionJuridicaReglas $gestor): JsonResponse
    {
        $registro = $this->versionesVisibles($request)->findOrFail($version);
        // Deben estar presentes incluso antes de la primera decision (ID null).
        $request->validate([
            'huella_version_esperada' => ['required', 'string'],
            'id_ultima_revision_esperada' => ['present', 'nullable', 'integer', 'min:1'],
            'estado_revision_esperado' => ['required', 'string'],
        ]);
        $conInstrucciones = array_key_exists('contrato_ejecucion', $registro->contenido);
        $cobertura = $request->validate($conInstrucciones ? [
            'confirmacion_instrucciones' => ['required', function (string $campo, mixed $valor, \Closure $fallar): void {
                if ($valor !== true) {
                    $fallar('Confirme expresamente la lectura de todas las instrucciones de esta copia.');
                }
            }],
            'huella_ejecucion_revisada' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ] : [
            'confirmacion_instrucciones' => ['prohibited'],
            'huella_ejecucion_revisada' => ['prohibited'],
        ]);
        $revision = $gestor->registrar($request->user(), $registro->getKey(),
            $request->only(['decision', 'observacion', 'fuentes_verificadas', 'vigencia_comprobada_en', 'confirmacion_humana', 'fecha_vencimiento']),
            $request->only(['huella_version_esperada', 'id_ultima_revision_esperada', 'estado_revision_esperado']),
            $conInstrucciones ? $cobertura['huella_ejecucion_revisada'] : null);

        return response()->json(['data' => (new RevisionReglasAudienciaResource($revision))->resolve($request)], 201);
    }

    public function revokeRevision(Request $request, int $revision, GestionarRevisionJuridicaReglas $gestor): JsonResponse
    {
        // El autor conserva la posibilidad de retirar su decision aun sin habilitacion vigente.
        $registro = RevisionReglasAudiencia::query()
            ->when(! $this->esAdministrador($request), fn ($q) => $q->where('id_usuario_revisor', $request->user()->getKey()))
            ->findOrFail($revision);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $gestor->revocarRevision($request->user(), $registro->getKey(), $datos['motivo']);

        return response()->json(['data' => (new RevisionReglasAudienciaResource($registro))->resolve($request)]);
    }

    private function esAdministrador(Request $request): bool
    {
        return User::query()->findOrFail($request->user()->getKey())->hasRole('administrador_plataforma');
    }

    private function versionesVisibles(Request $request): Builder
    {
        return VersionReglasAudiencia::query()->when(! $this->esAdministrador($request), function ($q) use ($request): void {
            $q->whereExists(fn ($h) => $h->selectRaw('1')->from('habilitaciones_revision_juridica as h')
                ->whereColumn('h.id_tipo_audiencia', 'versiones_reglas_audiencia.id_tipo_audiencia')
                ->where('h.id_usuario_revisor', $request->user()->getKey())
                ->whereRaw('h.fecha_vencimiento > clock_timestamp()')
                ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('revocaciones_habilitacion_juridica as rh')
                    ->whereColumn('rh.id_habilitacion', 'h.id_habilitacion')));
        });
    }

    private function habilitacionesVisibles(Request $request): Builder
    {
        return HabilitacionRevisionJuridica::query()->when(! $this->esAdministrador($request),
            fn ($q) => $q->where('id_usuario_revisor', $request->user()->getKey()));
    }

    private function filtros(Request $request): array
    {
        return $request->validate([
            'id_tipo_audiencia' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
    }

    private function pagina(Request $request, Builder $consulta, string $recurso, array $filtros): JsonResponse
    {
        $pagina = $consulta->paginate($filtros['por_pagina'] ?? 20);

        return response()->json([
            'data' => $pagina->getCollection()->map(fn ($registro) => (new $recurso($registro))->resolve($request))->all(),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(), 'total' => $pagina->total()],
        ]);
    }
}
