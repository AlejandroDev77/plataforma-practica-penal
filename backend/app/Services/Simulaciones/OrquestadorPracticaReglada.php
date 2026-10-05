<?php

namespace App\Services\Simulaciones;

use App\Models\MovimientoPracticaReglada;
use App\Models\RegistroIntervencionReglada;
use App\Models\Simulacion;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Proyección de la copia sellada, no una reserva ni una autorización de comandos. */
final readonly class OrquestadorPracticaReglada
{
    public function __construct(private ConsultarVinculacionPractica $vinculacion) {}

    public function ejecutar(User $actor, int $id): array
    {
        $diagnostico = $this->vinculacion->ejecutar($actor, $id);
        if (! $diagnostico['binding_current']) {
            return $this->bloqueada($diagnostico, $diagnostico['binding_status']);
        }
        try {
            return DB::transaction(function () use ($actor, $id, $diagnostico): array {
                $fila = DB::selectOne('SELECT jurissim_contexto_practica_bloqueado(?,?) contenido', [$id, $actor->getKey()]);
                $copia = json_decode($fila->contenido, true, 512, JSON_THROW_ON_ERROR);
                $s = Simulacion::query()->findOrFail($id);
                $movimiento = MovimientoPracticaReglada::query()->where('id_simulacion', $id)->orderByDesc('orden')->first();
                $etapas = collect($copia['etapas'])->where('activo', true)->keyBy('id_etapa');
                $etapa = $etapas->get($s->id_etapa_actual);
                $intervenciones = $s->intervenciones()->with('participante')->get();
                $recibos = RegistroIntervencionReglada::query()->where('id_simulacion', $id)->get()->keyBy('id_intervencion');
                $porEtapa = [];
                $integro = $recibos->count() === $intervenciones->count();
                foreach ($intervenciones as $posicion => $i) {
                    $indice = $porEtapa[$i->id_etapa] ?? 0;
                    $esperado = $s->configuracion['turnos_por_etapa'][$i->id_etapa][$indice] ?? null;
                    $r = $recibos->get($i->getKey());
                    $integro = $integro && $i->orden === $posicion + 1 && $esperado !== null && $r !== null
                        && (int) $r->id_propuesta_turno === $esperado['id_propuesta_turno']
                        && $i->participante?->rol === $esperado['rol']
                        && $i->participante?->controlado_por === ($esperado['rol'] === 'abogado_defensor' ? 'usuario' : 'ia');
                    $porEtapa[$i->id_etapa] = $indice + 1;
                }
                $turnos = $s->configuracion['turnos_por_etapa'][$s->id_etapa_actual] ?? [];
                $completados = $porEtapa[$s->id_etapa_actual] ?? 0;
                $pendiente = $turnos[$completados] ?? null;
                $acciones = $destinos = $bloqueos = [];
                $estado = $s->estado;
                $participante = null;
                if (! $integro) {
                    $estado = 'bloqueada';
                    $bloqueos[] = 'historial_incompatible';
                } elseif ($s->estado === 'preparando' && $movimiento === null && $intervenciones->isEmpty()) {
                    $acciones[] = 'iniciar';
                } elseif ($s->estado === 'activa' && $etapa !== null && $movimiento !== null
                    && $movimiento->estado_destino === $s->estado && $movimiento->id_etapa_destino === $s->id_etapa_actual) {
                    if ($pendiente !== null) {
                        $control = $pendiente['rol'] === 'abogado_defensor' ? 'usuario' : 'ia';
                        $candidatos = $s->participantes()->where('rol', $pendiente['rol'])->where('controlado_por', $control)
                            ->where('estado', 'activo')->where('id_expediente', $s->id_expediente)->where('id_analisis', $s->id_analisis)->get();
                        if ($candidatos->count() !== 1 || ($control === 'ia' && mb_strlen($pendiente['instruccion']) > 1500)) {
                            $estado = 'bloqueada';
                            $bloqueos[] = 'participante_o_instruccion_incompatible';
                        } else {
                            $p = $candidatos->sole();
                            $participante = ['id' => $p->getKey(), 'rol' => $p->rol, 'controlado_por' => $p->controlado_por];
                            $estado = $control === 'usuario' ? 'esperando_defensa' : 'esperando_agente';
                            $acciones[] = $control === 'usuario' ? 'registrar_defensa' : 'proponer_agente';
                        }
                    } elseif ($completados === count($turnos) && $turnos !== []) {
                        if ($etapa['es_final']) {
                            $estado = 'esperando_cierre';
                            $acciones[] = 'finalizar';
                        } else {
                            foreach ($copia['transiciones'] as $t) {
                                if ($t['activo'] && $t['id_etapa_origen'] === $s->id_etapa_actual && $etapas->has($t['id_etapa_destino'])) {
                                    $e = $etapas->get($t['id_etapa_destino']);
                                    $destinos[] = ['id_etapa' => $e['id_etapa'], 'nombre' => $e['nombre']];
                                }
                            }
                            $estado = count($destinos) > 1 ? 'esperando_destino' : 'esperando_avance';
                            if ($destinos !== []) {
                                $acciones[] = 'avanzar';
                            } else {
                                $estado = 'bloqueada';
                                $bloqueos[] = 'sin_destino_sellado';
                            }
                        }
                    } else {
                        $estado = 'bloqueada';
                        $bloqueos[] = 'turnos_incompatibles';
                    }
                } elseif ($s->estado !== 'finalizada') {
                    $estado = 'bloqueada';
                    $bloqueos[] = 'estado_incompatible';
                }

                return [
                    'simulation_id' => $id, 'binding_id' => $diagnostico['binding_id'], 'version_id' => $diagnostico['version_id'],
                    'activation_id' => $diagnostico['activation_id'], 'binding_status' => 'vigente',
                    'read_only' => true, 'execution_authorized' => false, 'requires_command_integration' => false,
                    'status' => $estado, 'simulation_state' => $s->estado, 'blocking_reasons' => $bloqueos,
                    'technical_candidate_actions' => $acciones, 'allowed_actions' => [],
                    'requires_provider_check' => in_array('proponer_agente', $acciones, true),
                    'type' => ['id_tipo_audiencia' => $s->id_tipo_audiencia, 'nombre' => $copia['tipo']['nombre']],
                    'stage' => $etapa === null ? null : ['id_etapa' => $etapa['id_etapa'], 'nombre' => $etapa['nombre']],
                    'current_participant' => $participante,
                    'current_turn' => $pendiente === null ? null : ['id_propuesta_turno' => $pendiente['id_propuesta_turno'],
                        'rol' => $pendiente['rol'], 'instruccion' => $pendiente['instruccion']],
                    'expected_context' => ['estado_esperado' => $s->estado, 'id_etapa_esperada' => $s->id_etapa_actual,
                        'id_movimiento_esperado' => $movimiento?->getKey(),
                        'id_turno_esperado' => $pendiente['id_propuesta_turno'] ?? null, 'orden_esperado' => $intervenciones->count() + 1],
                    'candidate_next_stages' => $destinos,
                    'completed_turns' => $completados, 'stage_turn_count' => count($turnos),
                ];
            });
        } catch (QueryException $error) {
            if (! in_array($error->errorInfo[0] ?? null, ['23514', '23505', '23503', '40001', '40P01', '55P03'], true)) {
                throw $error;
            }

            return $this->bloqueada($diagnostico, 'contexto_no_vigente');
        } catch (DomainException) {
            return $this->bloqueada($diagnostico, 'contexto_incompatible');
        }
    }

    private function bloqueada(array $d, string $motivo): array
    {
        return ['simulation_id' => $d['simulation_id'], 'binding_id' => $d['binding_id'],
            'version_id' => $d['version_id'], 'activation_id' => $d['activation_id'],
            'binding_status' => $motivo, 'status' => 'bloqueada', 'read_only' => true,
            'execution_authorized' => false, 'requires_command_integration' => false,
            'allowed_actions' => [], 'technical_candidate_actions' => [], 'blocking_reasons' => [$motivo],
            'requires_provider_check' => false, 'expected_context' => null, 'current_turn' => null];
    }
}
