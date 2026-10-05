<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BorradorAgenteReglado;
use App\Models\Intervencion;
use App\Models\RegistroIntervencionReglada;
use App\Models\User;
use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use App\Services\Simulaciones\ConfirmarAgenteReglado;
use App\Services\Simulaciones\CrearPracticaConReglas;
use App\Services\Simulaciones\MoverPracticaReglada;
use App\Services\Simulaciones\OrquestadorPracticaReglada;
use App\Services\Simulaciones\ProponerAgenteReglado;
use App\Services\Simulaciones\RegistrarDefensaReglada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PracticaRegladaController extends Controller
{
    public function crear(Request $request, int $expediente, CrearPracticaConReglas $crear): JsonResponse
    {
        $d = $this->campos($request, ['id_analisis' => $this->id(), 'id_tipo_audiencia' => $this->id(), 'id_activacion' => $this->id()]);
        $s = $crear->ejecutar($request->user(), $expediente, (int) $d['id_analisis'], (int) $d['id_tipo_audiencia'], (int) $d['id_activacion']);

        return response()->json(['data' => ['id_simulacion' => $s->getKey(), 'estado' => $s->estado]], 201);
    }

    public function consultar(Request $request, int $practica, OrquestadorPracticaReglada $orquestador): JsonResponse
    {
        return response()->json(['data' => $orquestador->ejecutar($request->user(), $practica)]);
    }

    public function mover(Request $request, int $practica, MoverPracticaReglada $mover): JsonResponse
    {
        $d = $this->campos($request, ['accion' => ['required', Rule::in(['iniciar', 'avanzar', 'finalizar'])],
            'estado_esperado' => ['required', Rule::in(['preparando', 'activa'])],
            'id_etapa_esperada' => ['present', 'nullable', 'integer', 'min:1'], 'id_destino' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $s = $mover->ejecutar($request->user(), $practica, $d['accion'], $d['estado_esperado'],
            isset($d['id_etapa_esperada']) ? (int) $d['id_etapa_esperada'] : null, isset($d['id_destino']) ? (int) $d['id_destino'] : null);

        return response()->json(['data' => ['id_simulacion' => $s->getKey(), 'estado' => $s->estado, 'id_etapa_actual' => $s->id_etapa_actual]]);
    }

    public function defensa(Request $request, int $practica, RegistrarDefensaReglada $registrar): JsonResponse
    {
        $d = $this->campos($request, [...$this->contexto(), 'contenido' => ['required', 'string', 'max:2000'],
            'ids_fragmento' => ['sometimes', 'array', 'list', 'max:10'], 'ids_fragmento.*' => $this->fragmento()]);
        $i = $registrar->ejecutar($request->user(), $practica, (int) $d['id_movimiento_esperado'], (int) $d['id_etapa_esperada'],
            (int) $d['id_turno_esperado'], (int) $d['orden_esperado'], $d['contenido'], $d['ids_fragmento'] ?? []);

        return $this->intervencion($i, 201);
    }

    public function proponer(Request $request, int $practica, ProponerAgenteReglado $proponer): JsonResponse
    {
        $d = $this->campos($request, [...$this->contexto(), 'ids_fragmento' => ['sometimes', 'array', 'list', 'max:8'],
            'ids_fragmento.*' => $this->fragmento(), 'autorizar_externo' => ['sometimes', $this->booleano()]]);
        $b = $proponer->ejecutar($request->user(), $practica, (int) $d['id_movimiento_esperado'], (int) $d['id_etapa_esperada'],
            (int) $d['id_turno_esperado'], (int) $d['orden_esperado'], $d['ids_fragmento'] ?? [], $d['autorizar_externo'] ?? false);

        return response()->json(['data' => $this->proyectarBorrador($b)], 201);
    }

    public function borrador(Request $request, int $practica, int $borrador): JsonResponse
    {
        $this->propiedad($request->user(), $practica);
        $b = BorradorAgenteReglado::query()->whereKey($borrador)->where('id_simulacion', $practica)
            ->where('id_usuario_responsable', $request->user()->getKey())->firstOrFail();

        return response()->json(['data' => $this->proyectarBorrador($b)]);
    }

    public function confirmar(Request $request, int $practica, int $borrador, ConfirmarAgenteReglado $confirmar): JsonResponse
    {
        $d = $this->campos($request, ['confirmacion_humana' => ['required', $this->booleano(), function ($atributo, $valor, $fallar): void {
            if ($valor !== true) {
                $fallar('Revise el borrador y confirme explícitamente con true.');
            }
        }]]);
        $i = $confirmar->ejecutar($request->user(), $practica, $borrador, $d['confirmacion_humana']);

        // 200 tanto en la primera confirmación como al repetirla: no promete una nueva inserción.
        return $this->intervencion($i);
    }

    public function proveedor(Request $request, int $practica, AgenteRegladoGateway $gateway): JsonResponse
    {
        $this->propiedad($request->user(), $practica);
        $p = $gateway->proveedor();

        // Lista cerrada: una pasarela no puede exponer secretos ni campos internos arbitrarios.
        return response()->json(['data' => ['provider' => $p['provider'], 'model' => $p['model'],
            'requires_external_authorization' => $p['requires_external_authorization']]]);
    }

    private function proyectarBorrador(BorradorAgenteReglado $b): array
    {
        $reloj = DB::selectOne('SELECT fecha_vencimiento <= clock_timestamp() vencido FROM borradores_agentes_reglados WHERE id_borrador=?', [$b->getKey()]);
        $registro = RegistroIntervencionReglada::query()->where('id_borrador', $b->getKey())->first();

        return ['id_borrador' => $b->getKey(), 'id_simulacion' => $b->id_simulacion,
            'id_movimiento' => $b->id_movimiento, 'id_etapa' => $b->id_etapa, 'id_propuesta_turno' => $b->id_propuesta_turno, 'orden' => $b->orden,
            'respuesta' => $b->respuesta, 'proveedor' => $b->proveedor, 'modelo_ia' => $b->modelo_ia,
            'autorizar_externo' => $b->autorizar_externo, 'fuentes_enviadas' => $b->preparacion['contexto']['sources'],
            'fecha_creacion' => $b->fecha_creacion->toIso8601String(), 'fecha_vencimiento' => $b->fecha_vencimiento->toIso8601String(),
            'vencido' => (bool) $reloj->vencido, 'id_intervencion_confirmada' => $registro?->id_intervencion,
            'requires_human_review' => true, 'confirmation_authorized' => false];
    }

    private function intervencion(Intervencion $i, int $status = 200): JsonResponse
    {
        return response()->json(['data' => ['id_intervencion' => $i->getKey(), 'id_simulacion' => $i->id_simulacion,
            'id_etapa' => $i->id_etapa, 'orden' => $i->orden, 'contenido' => $i->contenido, 'modelo_ia' => $i->modelo_ia]], $status);
    }

    private function propiedad(User $actor, int $id): void
    {
        $actor->simulaciones()->whereKey($id)->whereHas('expediente', fn ($q) => $q->where('id_usuario', $actor->getKey()))->firstOrFail();
    }

    private function campos(Request $request, array $reglas): array
    {
        $permitidos = array_filter(array_keys($reglas), fn ($k) => ! str_contains($k, '.'));
        if (array_diff(array_keys($request->all()), $permitidos) !== []) {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud contiene campos no admitidos.']);
        }

        return $request->validate($reglas);
    }

    private function id(): array
    {
        return ['required', 'integer', 'min:1'];
    }

    private function contexto(): array
    {
        return ['id_movimiento_esperado' => $this->id(), 'id_etapa_esperada' => $this->id(),
            'id_turno_esperado' => $this->id(), 'orden_esperado' => $this->id()];
    }

    private function fragmento(): array
    {
        return ['required', 'integer', 'min:1', 'distinct:strict', function ($atributo, $valor, $fallar): void {
            if (! is_int($valor)) {
                $fallar('Los identificadores de fragmento deben ser enteros JSON.');
            }
        }];
    }

    private function booleano(): \Closure
    {
        return function ($atributo, $valor, $fallar): void {
            if (! is_bool($valor)) {
                $fallar('Use un booleano JSON explícito.');
            }
        };
    }
}
