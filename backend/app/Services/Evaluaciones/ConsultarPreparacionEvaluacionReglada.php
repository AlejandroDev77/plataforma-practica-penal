<?php

namespace App\Services\Evaluaciones;

use App\Models\MovimientoPracticaReglada;
use App\Models\Rubrica;
use App\Models\Simulacion;
use App\Models\User;
use App\Services\Simulaciones\OrquestadorPracticaReglada;
use Illuminate\Support\Facades\DB;

/** Diagnóstico privado. No congela contexto, acredita rúbricas ni autoriza generación. */
final readonly class ConsultarPreparacionEvaluacionReglada
{
    public function __construct(private OrquestadorPracticaReglada $orquestador) {}

    public function ejecutar(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            // El orquestador conserva el orden de bloqueo y la comprobación de ambas propiedades.
            $o = $this->orquestador->ejecutar($actor, $id);
            $resultado = [
                'simulation_id' => $id, 'read_only' => true, 'execution_authorized' => false,
                'allowed_actions' => [], 'technical_ready' => false,
                'rubric_review_status' => 'no_verificable',
                'blocking_reasons' => ['revision_rubrica_no_verificable', 'ejecucion_no_integrada'],
                'technical_blocking_reasons' => [], 'rubric' => null, 'transcript' => null,
            ];
            if ($o['binding_status'] !== 'vigente' || $o['status'] === 'bloqueada') {
                $resultado['technical_blocking_reasons'] = ['practica_no_vigente'];

                return $resultado;
            }
            if ($o['status'] !== 'finalizada') {
                $resultado['technical_blocking_reasons'] = ['practica_no_finalizada'];

                return $resultado;
            }
            $s = Simulacion::query()->findOrFail($id);
            $ultimo = MovimientoPracticaReglada::query()->where('id_simulacion', $id)->orderByDesc('orden')->first();
            if ($s->fecha_inicio === null || $s->fecha_fin === null || $ultimo?->accion !== 'finalizar'
                || $ultimo->estado_destino !== 'finalizada' || $ultimo->id_etapa_destino !== $s->id_etapa_actual
                || $o['stage_turn_count'] < 1 || $o['completed_turns'] !== $o['stage_turn_count']) {
                $resultado['technical_blocking_reasons'] = ['cierre_no_verificable'];

                return $resultado;
            }

            [$rubrica, $motivo, $caracteres] = $this->rubrica($s);
            if (trim($o['type']['nombre']) === '' || mb_strlen($o['type']['nombre'], 'UTF-8') > 100) {
                $resultado['technical_blocking_reasons'][] = 'tipo_audiencia_incompatible';
            }
            $resultado['rubric'] = $rubrica;
            if ($motivo !== null) {
                $resultado['technical_blocking_reasons'][] = $motivo;
            }
            $turnos = $s->intervenciones()->with(['participante', 'fuentes'])->limit(41)->get();
            $defensa = 0;
            foreach ($turnos as $turno) {
                $rol = $turno->participante?->rol;
                $texto = trim((string) $turno->contenido);
                $longitud = mb_strlen($texto, 'UTF-8');
                if (! in_array($rol, ['juez', 'fiscal', 'abogado_defensor'], true) || $longitud < 1 || $longitud > 2000) {
                    $resultado['technical_blocking_reasons'][] = 'transcripcion_incompatible';
                }
                $caracteres += $longitud;
                if ($rol !== 'abogado_defensor') {
                    continue;
                }
                $defensa++;
                if ($turno->fuentes->count() > 10) {
                    $resultado['technical_blocking_reasons'][] = 'fuentes_incompatibles';
                }
                foreach ($turno->fuentes as $fuente) {
                    $meta = is_array($fuente->metadatos) ? $fuente->metadatos : [];
                    $extracto = trim((string) $fuente->fragmento_utilizado);
                    if (! in_array($meta['tipo_fuente'] ?? null, ['expediente', 'juridica'], true) || $extracto === ''
                        || mb_strlen($extracto, 'UTF-8') > 1000
                        || mb_strlen((string) ($meta['titulo'] ?? 'Fuente validada'), 'UTF-8') > 300
                        || mb_strlen((string) ($meta['localizador'] ?? 'Referencia'), 'UTF-8') > 200) {
                        $resultado['technical_blocking_reasons'][] = 'fuentes_incompatibles';
                    }
                    // Conservador: cuenta el extracto íntegro, sin truncar para eludir el límite.
                    $caracteres += mb_strlen($extracto, 'UTF-8')
                        + mb_strlen((string) ($meta['titulo'] ?? 'Fuente validada'), 'UTF-8')
                        + mb_strlen((string) ($meta['localizador'] ?? 'Referencia'), 'UTF-8');
                }
            }
            if ($turnos->count() > 40) {
                $resultado['technical_blocking_reasons'][] = 'limite_intervenciones';
            }
            if ($defensa === 0) {
                $resultado['technical_blocking_reasons'][] = 'sin_defensa';
            }
            if ($caracteres > 20000) {
                $resultado['technical_blocking_reasons'][] = 'limite_contexto';
            }
            if ($s->evaluaciones()->whereIn('estado', ['pendiente', 'procesando'])->exists()) {
                $resultado['technical_blocking_reasons'][] = 'evaluacion_en_curso';
            }
            $resultado['transcript'] = ['intervention_count' => $s->intervenciones()->count(),
                'defense_count' => $defensa, 'context_character_count' => $caracteres,
                'complete_count' => $turnos->count() <= 40];
            $resultado['technical_blocking_reasons'] = array_values(array_unique($resultado['technical_blocking_reasons']));
            $resultado['technical_ready'] = $resultado['technical_blocking_reasons'] === [];

            return $resultado;
        });
    }

    private function rubrica(Simulacion $s): array
    {
        $candidatas = Rubrica::query()->where('activo', true)
            ->where(fn ($q) => $q->whereNull('id_tipo_audiencia')->orWhere('id_tipo_audiencia', $s->id_tipo_audiencia))
            ->where(fn ($q) => $q->whereNull('rol_aplicable')->orWhere('rol_aplicable', $s->rol_usuario))
            ->orderByRaw('(CASE WHEN id_tipo_audiencia IS NULL THEN 0 ELSE 1 END + CASE WHEN rol_aplicable IS NULL THEN 0 ELSE 2 END) DESC')
            ->limit(2)->get();
        $especificidad = fn (Rubrica $r): int => (int) ($r->id_tipo_audiencia !== null) + 2 * (int) ($r->rol_aplicable !== null);
        $mejor = $candidatas->max($especificidad);
        $elegidas = $candidatas->filter(fn ($r) => $especificidad($r) === $mejor);
        if ($elegidas->isEmpty()) {
            return [null, 'sin_rubrica', 0];
        }
        if ($elegidas->count() !== 1) {
            return [null, 'rubrica_ambigua', 0];
        }
        $r = $elegidas->sole();
        $criterios = $r->criterios()->limit(21)->get();
        if (preg_match('/\S/u', $r->nombre) !== 1 || mb_strlen($r->nombre, 'UTF-8') > 255 || $r->version < 1
            || $criterios->isEmpty() || $criterios->count() > 20 || $criterios->pluck('orden')->duplicates()->isNotEmpty()) {
            return [null, 'rubrica_incompatible', 0];
        }
        $caracteres = 0;
        $pesos = 0.0;
        foreach ($criterios as $c) {
            $peso = (float) $c->peso;
            $maximo = (float) $c->puntaje_maximo;
            if ($c->orden < 1 || preg_match('/\S/u', $c->nombre) !== 1 || preg_match('/\S/u', (string) $c->descripcion) !== 1
                || mb_strlen($c->nombre, 'UTF-8') > 255 || mb_strlen($c->descripcion, 'UTF-8') > 2000
                || ! is_finite($peso) || $peso <= 0 || ! is_finite($maximo) || $maximo <= 0) {
                return [null, 'rubrica_incompatible', 0];
            }
            $pesos += $peso;
            $caracteres += mb_strlen($c->nombre, 'UTF-8') + mb_strlen($c->descripcion, 'UTF-8');
        }
        if ($pesos <= 0 || ! is_finite($pesos)) {
            return [null, 'rubrica_incompatible', 0];
        }

        return [['id_rubrica' => $r->getKey(), 'version' => $r->version, 'criterion_count' => $criterios->count()], null, $caracteres];
    }
}
