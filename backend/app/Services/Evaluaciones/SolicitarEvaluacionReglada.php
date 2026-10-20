<?php

namespace App\Services\Evaluaciones;

use App\Models\ContextoEvaluacionReglada;
use App\Models\Evaluacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SolicitarEvaluacionReglada
{
    public function __construct(private PrepararContextoEvaluacionReglada $preparar) {}

    public function ejecutar(User $actor, int $id, string $huella, mixed $confirmacion): Evaluacion
    {
        if ($confirmacion !== true || preg_match('/^[0-9a-f]{64}$/', $huella) !== 1) {
            throw ValidationException::withMessages(['consentimiento' => 'Confirme el envío exacto a OpenAI.']);
        }

        $evaluacion = DB::transaction(function () use ($actor, $id, $huella): Evaluacion {
            $simulacion = $actor->simulaciones()->whereKey($id)->lockForUpdate()->firstOrFail();
            $contexto = $this->preparar->ejecutar($actor, $id);
            if (! $contexto['can_request'] || ! hash_equals((string) $contexto['fingerprint'], $huella)) {
                throw ValidationException::withMessages(['contexto' => 'El acta, rúbrica o activación cambió. Revise el nuevo contexto antes de consentir.']);
            }
            $activacion = $contexto['activation'];
            $contenido = $contexto['preview'];
            $rubrica = [
                'id' => $activacion['id_rubrica'],
                'name' => $contenido['rubric_name'], 'version' => $contenido['rubric_version'],
                'criteria' => array_map(fn (array $c): array => [
                    'id' => $c['id'], 'name' => $c['name'], 'description' => $c['description'],
                    'weight' => $c['weight'], 'max_score' => $c['max_score'],
                ], $contenido['criteria']),
            ];
            $fila = $simulacion->evaluaciones()->create([
                'id_rubrica' => $activacion['id_rubrica'], 'estado' => 'pendiente',
                'datos_evaluacion' => ['schema_version' => '2.0', 'reglada' => true,
                    'rubric' => $rubrica, 'requires_human_review' => true],
            ]);
            ContextoEvaluacionReglada::query()->create([
                'id_evaluacion' => $fila->getKey(), 'id_version_rubrica' => $activacion['id_version_rubrica'],
                'id_revision_rubrica' => $activacion['id_revision_rubrica'],
                'id_activacion_rubrica' => $activacion['id_activacion_rubrica'],
                'id_usuario_consentimiento' => $actor->getKey(), 'proveedor' => 'openai',
                'huella_contexto' => $contexto['fingerprint'], 'contenido' => $contenido,
                'consentimiento_externo' => true, 'fecha_consentimiento' => now(),
            ]);

            return $fila;
        }, 3);

        return $evaluacion;
    }
}
