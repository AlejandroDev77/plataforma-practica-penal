<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Evaluacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Evaluacion */
final class EvaluacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $datos = is_array($this->datos_evaluacion) ? $this->datos_evaluacion : [];
        $rubrica = $this->resource->relationLoaded('rubrica') ? $this->rubrica : null;

        return [
            'id' => $this->getKey(),
            'status' => $this->estado,
            'score_percent' => $this->puntaje_total,
            'summary' => $this->estado === 'procesado' ? ($datos['summary'] ?? null) : null,
            'strengths' => $this->estado === 'procesado' ? ($datos['strengths'] ?? []) : [],
            'errors' => $this->estado === 'procesado' ? ($datos['errors'] ?? []) : [],
            'recommendations' => $this->estado === 'procesado' ? ($datos['recommendations'] ?? []) : [],
            'requires_human_review' => true,
            'message' => $this->estado === 'error'
                ? 'No se pudo completar la evaluación. Revise la configuración y consulte el estado antes de repetirla.'
                : null,
            'provider' => $this->estado === 'procesado' ? ($datos['provider'] ?? null) : null,
            'rubric' => $rubrica === null ? null : [
                'name' => $datos['rubric']['name'] ?? $rubrica->nombre,
                'version' => $datos['rubric']['version'] ?? $rubrica->version,
            ],
            'criteria' => $this->whenLoaded('resultados', fn (): array => ($datos['reglada'] ?? false)
                ? ($datos['criteria'] ?? [])
                : $this->resultados
                    ->map(fn ($resultado): array => [
                        'criterion_id' => $resultado->id_criterio,
                        'name' => $resultado->criterio?->nombre,
                        'score' => $resultado->puntaje,
                        'max_score' => $resultado->criterio?->puntaje_maximo,
                        'feedback' => $resultado->comentario,
                        'evidence' => $resultado->evidencia,
                    ])
                    ->values()
                    ->all()),
            'created_at' => $this->fecha_creacion?->toISOString(),
            'evaluated_at' => $this->fecha_evaluacion?->toISOString(),
        ];
    }
}
