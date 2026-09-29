<?php

namespace App\Services\Evaluaciones;

use App\Models\Evaluacion;
use App\Models\Rubrica;
use App\Models\Simulacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class SolicitarEvaluacion
{
    public function ejecutar(Simulacion $simulacion): Evaluacion
    {
        return DB::transaction(function () use ($simulacion): Evaluacion {
            $actual = Simulacion::query()
                ->whereKey($simulacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== 'finalizada') {
                throw ValidationException::withMessages([
                    'simulacion' => 'La evaluación solo está disponible cuando la simulación finalizó.',
                ]);
            }

            if ($actual->rol_usuario !== 'abogado_defensor') {
                throw ValidationException::withMessages([
                    'simulacion' => 'Todavía solo se evalúan prácticas del rol de defensa.',
                ]);
            }

            $candidatas = Rubrica::query()
                ->with('criterios')
                ->where('activo', true)
                ->where(fn ($query) => $query->whereNull('id_tipo_audiencia')
                    ->orWhere('id_tipo_audiencia', $actual->id_tipo_audiencia))
                ->where(fn ($query) => $query->whereNull('rol_aplicable')
                    ->orWhere('rol_aplicable', $actual->rol_usuario))
                ->get();

            $mejorCoincidencia = $candidatas->max(fn (Rubrica $rubrica): int => (int) ($rubrica->id_tipo_audiencia !== null) + 2 * (int) ($rubrica->rol_aplicable !== null)
            );
            $rubricasAplicables = $candidatas->filter(fn (Rubrica $rubrica): bool => (int) ($rubrica->id_tipo_audiencia !== null) + 2 * (int) ($rubrica->rol_aplicable !== null)
                === $mejorCoincidencia
            )->values();

            if ($rubricasAplicables->isEmpty()) {
                throw ValidationException::withMessages([
                    'rubric' => 'No hay una rúbrica activa para este tipo de audiencia y rol.',
                ]);
            }

            if ($rubricasAplicables->count() !== 1) {
                throw ValidationException::withMessages([
                    'rubric' => 'Hay varias rúbricas activas igualmente aplicables; debe quedar una sola.',
                ]);
            }

            /** @var Rubrica $rubrica */
            $rubrica = $rubricasAplicables->first();
            $criterios = $rubrica->criterios->sortBy('orden')->values();

            if ($criterios->isEmpty() || $criterios->count() > 20) {
                throw ValidationException::withMessages([
                    'rubric' => 'La rúbrica activa debe contener entre 1 y 20 criterios.',
                ]);
            }

            $enCurso = $actual->evaluaciones()
                ->whereIn('estado', ['pendiente', 'procesando'])
                ->exists();

            abort_if($enCurso, Response::HTTP_CONFLICT, 'Ya hay una evaluación en curso para esta simulación.');

            $intervenciones = $actual->intervenciones()
                ->with(['participante', 'fuentes'])
                ->orderBy('orden')
                ->get();
            $turnosValidos = $intervenciones->filter(fn ($turno): bool => in_array(
                $turno->participante?->rol,
                ['abogado_defensor', 'juez', 'fiscal'],
                true,
            ));

            if (! $turnosValidos->contains(fn ($turno): bool => $turno->participante?->rol === 'abogado_defensor')) {
                throw ValidationException::withMessages([
                    'transcript' => 'La simulación debe contener intervenciones de la defensa.',
                ]);
            }

            if ($turnosValidos->count() > 40) {
                throw ValidationException::withMessages([
                    'transcript' => 'La transcripción excede el límite de 40 intervenciones evaluables.',
                ]);
            }

            $caracteres = $criterios->sum(fn ($criterio): int => mb_strlen($criterio->nombre, 'UTF-8') + mb_strlen($criterio->descripcion, 'UTF-8')
            );
            foreach ($turnosValidos as $turno) {
                $contenido = trim((string) $turno->contenido);
                if ($contenido === '' || mb_strlen($contenido, 'UTF-8') > 2_000) {
                    throw ValidationException::withMessages([
                        'transcript' => 'Cada intervención evaluable debe tener texto y no exceder 2.000 caracteres.',
                    ]);
                }

                $caracteres += mb_strlen($contenido, 'UTF-8');
                if ($turno->participante?->rol === 'abogado_defensor') {
                    if ($turno->fuentes->count() > 10) {
                        throw ValidationException::withMessages([
                            'transcript' => 'Una intervención supera el límite de diez fuentes adjuntas.',
                        ]);
                    }

                    foreach ($turno->fuentes as $fuente) {
                        $metadatos = is_array($fuente->metadatos) ? $fuente->metadatos : [];
                        $caracteres += mb_strlen((string) ($metadatos['titulo'] ?? 'Fuente validada'), 'UTF-8');
                        $caracteres += mb_strlen((string) ($metadatos['localizador'] ?? 'Referencia'), 'UTF-8');
                        $caracteres += mb_strlen((string) $fuente->fragmento_utilizado, 'UTF-8');
                    }
                }
            }

            if ($caracteres > 20_000) {
                throw ValidationException::withMessages([
                    'transcript' => 'La rúbrica y transcripción exceden el límite de 20.000 caracteres.',
                ]);
            }

            $criteriosSnapshot = $criterios->map(fn ($criterio): array => [
                'id' => $criterio->getKey(),
                'name' => $criterio->nombre,
                'description' => $criterio->descripcion,
                'weight' => (float) $criterio->peso,
                'max_score' => (float) $criterio->puntaje_maximo,
            ])->all();

            return $actual->evaluaciones()->create([
                'id_rubrica' => $rubrica->getKey(),
                'estado' => 'pendiente',
                'datos_evaluacion' => [
                    'schema_version' => '1.0',
                    'rubric' => [
                        'id' => $rubrica->getKey(),
                        'name' => $rubrica->nombre,
                        'version' => $rubrica->version,
                        'criteria' => $criteriosSnapshot,
                    ],
                    'requires_human_review' => true,
                ],
            ]);
        }, 3);
    }
}
