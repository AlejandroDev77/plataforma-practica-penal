<?php

namespace App\Services\Simulaciones;

use App\Models\User;

final readonly class OrquestadorAudiencia
{
    public function __construct(private ResolverTurnoAudiencia $turnos) {}

    /** @return array<string, mixed> */
    public function consultar(User $usuario, int $idSimulacion): array
    {
        // El navegador nunca decide propietario, rol, secuencia o revision.
        $simulacion = $usuario->simulaciones()
            ->whereKey($idSimulacion)
            ->whereHas('expediente', fn ($query) => $query->where('id_usuario', $usuario->getKey()))
            ->with(['tipoAudiencia', 'etapaActual', 'analisis.revisiones', 'participantes'])
            ->firstOrFail();
        $etapa = $simulacion->etapaActual;
        $revision = $simulacion->analisis?->revisiones->first();
        $bloqueos = [];
        $acciones = [];
        $estadoTecnico = 'bloqueada';
        $participante = null;
        $turno = null;
        $destinos = [];

        if ($simulacion->estado !== 'activa') {
            $bloqueos[] = 'audiencia_no_activa';
        }
        if (! $simulacion->tipoAudiencia?->activo) {
            $bloqueos[] = 'tipo_audiencia_no_disponible';
        }
        if ($etapa === null || ! $etapa->activo || $etapa->id_tipo_audiencia !== $simulacion->id_tipo_audiencia) {
            $bloqueos[] = 'etapa_no_disponible';
        }
        if ($simulacion->analisis?->estado !== 'procesado' || $revision?->decision !== 'aprobado') {
            $bloqueos[] = 'analisis_no_aprobado';
        }
        if ($simulacion->rol_usuario !== 'abogado_defensor') {
            $bloqueos[] = 'rol_usuario_no_soportado';
        }

        $configuracion = $simulacion->configuracion ?? [];
        $mapa = is_array($configuracion) ? ($configuracion['turnos_por_etapa'] ?? []) : null;
        $secuencia = is_array($mapa) ? ($mapa[$simulacion->id_etapa_actual] ?? null) : null;
        if (! is_array($configuracion) || ! is_array($mapa)) {
            $bloqueos[] = 'secuencia_invalida_o_rol_no_soportado';
        } elseif ($secuencia === null || $secuencia === []) {
            $bloqueos[] = 'secuencia_no_configurada';
        } elseif (! $this->secuenciaValida($secuencia)) {
            $bloqueos[] = 'secuencia_invalida_o_rol_no_soportado';
        } else {
            $intervenciones = $simulacion->intervenciones()
                ->where('id_etapa', $simulacion->id_etapa_actual)
                ->with('participante')
                ->get();
            $cantidad = $intervenciones->count();
            $turno = $this->turnos->resumir($simulacion, $cantidad);
            if ($cantidad > count($secuencia)) {
                $bloqueos[] = 'historial_incompatible_con_secuencia';
            } else {
                foreach ($intervenciones as $indice => $intervencion) {
                    $rolEsperado = $secuencia[$indice]['rol'];
                    $controlEsperado = $rolEsperado === $simulacion->rol_usuario ? 'usuario' : 'ia';
                    if ($intervencion->participante?->rol !== $rolEsperado
                        || $intervencion->participante?->controlado_por !== $controlEsperado) {
                        $bloqueos[] = 'historial_incompatible_con_secuencia';
                        break;
                    }
                }
            }

            if (! $turno['completa']) {
                $rol = $turno['rol'];
                $control = $rol === $simulacion->rol_usuario ? 'usuario' : 'ia';
                $candidatos = $simulacion->participantes->filter(fn ($actor): bool => $actor->rol === $rol
                    && $actor->controlado_por === $control && $actor->estado === 'activo');
                if ($candidatos->count() !== 1) {
                    $bloqueos[] = $candidatos->isEmpty() ? 'participante_no_disponible' : 'participante_ambiguo';
                } else {
                    $participante = $candidatos->first();
                }
                if ($control === 'ia' && trim($secuencia[$cantidad]['instruccion'] ?? '') === '') {
                    $bloqueos[] = 'instruccion_agente_ausente';
                }
                if ($bloqueos === []) {
                    $estadoTecnico = $control === 'usuario' ? 'esperando_usuario' : 'esperando_agente';
                    $acciones = [$control === 'usuario' ? 'submit_text_intervention' : 'request_agent_proposal'];
                }
            } elseif ($etapa !== null && $etapa->activo && $etapa->id_tipo_audiencia === $simulacion->id_tipo_audiencia) {
                $destinos = $etapa->transicionesSalientes()
                    ->where('activo', true)
                    ->where('id_tipo_audiencia', $simulacion->id_tipo_audiencia)
                    ->whereHas('destino', fn ($query) => $query->where('activo', true)
                        ->where('id_tipo_audiencia', $simulacion->id_tipo_audiencia))
                    ->orderBy('id_etapa_destino')->with('destino')->get()
                    ->map(fn ($transicion): array => [
                        'id' => $transicion->destino->getKey(),
                        'code' => $transicion->destino->codigo,
                        'name' => $transicion->destino->nombre,
                    ])->all();
                if (! $etapa->es_final && $destinos === []) {
                    $bloqueos[] = 'etapa_sin_transicion_disponible';
                }
                if ($bloqueos === []) {
                    $estadoTecnico = $etapa->es_final ? 'esperando_cierre'
                        : (count($destinos) > 1 ? 'esperando_destino' : 'esperando_avance');
                    $acciones = [$etapa->es_final ? 'finish_simulation' : 'advance_stage'];
                }
            }
        }

        // No existe todavia un registro verificable de aprobacion de secuencias.
        // Flags de configuracion, turnos activos o revision del analisis no lo sustituyen.
        return [
            'simulation_id' => $simulacion->getKey(),
            'read_only' => true,
            'technical_status' => $simulacion->estado === 'finalizada' ? 'finalizada' : $estadoTecnico,
            'practice_ready' => false,
            'legal_review_status' => 'no_acreditada',
            'allowed_actions' => [],
            'technical_candidate_actions' => $acciones,
            'technical_blocking_reasons' => array_values(array_unique($bloqueos)),
            'blocking_reasons' => array_values(array_unique([...$bloqueos, 'revision_juridica_secuencia_no_acreditada'])),
            'requires_provider_check' => in_array('request_agent_proposal', $acciones, true),
            'analysis' => ['id' => $simulacion->id_analisis, 'review_decision' => $revision?->decision],
            'stage' => $etapa === null ? null : ['id' => $etapa->getKey(), 'code' => $etapa->codigo, 'name' => $etapa->nombre],
            'current_turn' => $turno === null ? null : [
                'configured' => $turno['configurada'], 'complete' => $turno['completa'],
                'order' => $turno['orden'], 'role' => $turno['rol'],
            ],
            'current_participant' => $participante === null ? null : [
                'id' => $participante->getKey(), 'role' => $participante->rol, 'controlled_by' => $participante->controlado_por,
            ],
            'candidate_next_stages' => in_array('advance_stage', $acciones, true) ? $destinos : [],
        ];
    }

    private function secuenciaValida(mixed $secuencia): bool
    {
        if (! is_array($secuencia) || ! array_is_list($secuencia) || count($secuencia) > 100) {
            return false;
        }
        $anterior = 0;
        foreach ($secuencia as $turno) {
            if (! is_array($turno) || ! is_int($turno['orden'] ?? null) || $turno['orden'] <= $anterior
                || ! in_array($turno['rol'] ?? null, ['juez', 'fiscal', 'abogado_defensor'], true)
                || (isset($turno['instruccion']) && ! is_string($turno['instruccion']))) {
                return false;
            }
            $anterior = $turno['orden'];
        }

        return true;
    }
}
