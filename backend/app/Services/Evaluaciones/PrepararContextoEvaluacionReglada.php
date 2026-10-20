<?php

namespace App\Services\Evaluaciones;

use App\Models\MovimientoPracticaReglada;
use App\Models\Simulacion;
use App\Models\User;
use App\Models\VersionRubricaEvaluacion;
use App\Services\Simulaciones\OrquestadorPracticaReglada;
use Illuminate\Support\Facades\DB;

/** Prepara el contenido exacto que verá la persona antes de autorizar un envío externo. */
final readonly class PrepararContextoEvaluacionReglada
{
    public function __construct(private OrquestadorPracticaReglada $orquestador, private ConsultarRubricaActivada $activada) {}

    public function ejecutar(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $actor->simulaciones()->whereKey($id)->firstOrFail();
            $o = $this->orquestador->ejecutar($actor, $id);
            $bloqueos = [];
            if ($o['binding_status'] !== 'vigente' || $o['status'] !== 'finalizada') {
                $bloqueos[] = 'practica_no_finalizada_o_vigente';
            }
            $s = Simulacion::query()->whereKey($id)->firstOrFail();
            $ultimo = MovimientoPracticaReglada::query()->where('id_simulacion', $id)->orderByDesc('orden')->first();
            if ($s->fecha_inicio === null || $s->fecha_fin === null || $ultimo?->accion !== 'finalizar'
                || $ultimo->estado_destino !== 'finalizada' || $ultimo->id_etapa_destino !== $s->id_etapa_actual
                || ($o['stage_turn_count'] ?? 0) < 1 || ($o['completed_turns'] ?? -1) !== $o['stage_turn_count']) {
                $bloqueos[] = 'cierre_no_verificable';
            }

            $activacion = $this->activada->ejecutar($s->id_tipo_audiencia, $s->rol_usuario);
            $contenidoRubrica = null;
            if ($activacion === null || $activacion['rubrica'] === null
                || $activacion['estado_activacion'] !== 'vigente' || $activacion['estado_revision'] !== 'aprobado') {
                $bloqueos[] = 'rubrica_revisada_no_vigente';
            } else {
                $version = VersionRubricaEvaluacion::query()->find($activacion['rubrica']['id_version_rubrica']);
                $copia = $version?->contenido;
                if (! is_array($copia) || ! is_array($copia['criterios'] ?? null)
                    || count($copia['criterios']) < 1 || count($copia['criterios']) > 20) {
                    $bloqueos[] = 'copia_rubrica_incompatible';
                } else {
                    $contenidoRubrica = [
                        'rubric_name' => $copia['rubrica']['nombre'],
                        'rubric_version' => (int) $copia['rubrica']['version_catalogo'],
                        'criteria' => array_map(fn (array $c): array => [
                            'id' => (int) $c['id_criterio'], 'name' => $c['nombre'],
                            'description' => $c['descripcion'], 'weight' => (float) $c['peso'],
                            'max_score' => (float) $c['puntaje_maximo'],
                        ], $copia['criterios']),
                    ];
                }
            }

            $intervenciones = $s->intervenciones()->with(['participante', 'fuentes'])->orderBy('orden')->limit(41)->get();
            if ($intervenciones->count() > 40) {
                $bloqueos[] = 'limite_intervenciones';
            }
            $transcript = [];
            foreach ($intervenciones as $turno) {
                $rol = $turno->participante?->rol;
                if (! in_array($rol, ['abogado_defensor', 'juez', 'fiscal'], true)) {
                    $bloqueos[] = 'intervencion_incompatible';
                    continue;
                }
                $texto = trim((string) $turno->contenido);
                if ($texto === '' || mb_strlen($texto, 'UTF-8') > 2000) {
                    $bloqueos[] = 'intervencion_incompatible';
                }
                $fuentes = [];
                if ($rol === 'abogado_defensor') {
                    if ($turno->fuentes->count() > 10) {
                        $bloqueos[] = 'fuentes_incompatibles';
                    }
                    foreach ($turno->fuentes as $fuente) {
                        $meta = is_array($fuente->metadatos) ? $fuente->metadatos : [];
                        $extracto = trim((string) $fuente->fragmento_utilizado);
                        if (! in_array($meta['tipo_fuente'] ?? null, ['expediente', 'juridica'], true)
                            || $extracto === '' || mb_strlen($extracto, 'UTF-8') > 1000) {
                            $bloqueos[] = 'fuentes_incompatibles';
                        }
                        $fuentes[] = [
                            'kind' => $meta['tipo_fuente'] ?? 'expediente',
                            'title' => mb_substr(trim((string) ($meta['titulo'] ?? 'Fuente validada')) ?: 'Fuente validada', 0, 300),
                            'locator' => mb_substr(trim((string) ($meta['localizador'] ?? 'Referencia')) ?: 'Referencia', 0, 200),
                            'excerpt' => $extracto,
                        ];
                    }
                }
                $transcript[] = ['order' => (int) $turno->orden, 'role' => $rol, 'content' => $texto, 'sources' => $fuentes];
            }
            if (! collect($transcript)->contains(fn (array $t): bool => $t['role'] === 'abogado_defensor')) {
                $bloqueos[] = 'sin_intervencion_defensa';
            }
            if ($s->evaluaciones()->exists()) {
                $bloqueos[] = 'evaluacion_ya_solicitada';
            }

            $payload = $contenidoRubrica === null ? null : [
                'hearing_type' => (string) $o['type']['nombre'],
                'user_role' => $s->rol_usuario,
                'rubric_name' => $contenidoRubrica['rubric_name'],
                'rubric_version' => $contenidoRubrica['rubric_version'],
                'criteria' => $contenidoRubrica['criteria'],
                'transcript' => $transcript,
            ];
            if ($payload !== null) {
                $caracteres = mb_strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'UTF-8');
                if ($caracteres > 20000) {
                    $bloqueos[] = 'limite_contexto';
                }
            }
            $bloqueos = array_values(array_unique($bloqueos));
            $huella = $payload === null ? null : hash('sha256', json_encode($this->canonico([
                'provider' => 'openai', 'user_id' => $actor->getKey(),
                'activation' => $activacion['rubrica'] ?? null, 'request' => $payload,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return [
                'simulation_id' => $id, 'can_request' => $payload !== null && $bloqueos === [],
                'provider' => 'openai', 'requires_external_authorization' => true,
                'blocking_reasons' => $bloqueos, 'fingerprint' => $huella,
                'preview' => $payload,
                'activation' => $activacion['rubrica'] ?? null,
            ];
        });
    }

    private function canonico(array $valor): array
    {
        foreach ($valor as $clave => $elemento) {
            if (is_array($elemento)) {
                $valor[$clave] = $this->canonico($elemento);
            }
        }
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }
}
