<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Simulacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Simulacion */
final class SimulacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'case_id' => $this->id_expediente,
            'analysis_id' => $this->id_analisis,
            'user_role' => $this->rol_usuario,
            'status' => $this->estado,
            'created_at' => $this->fecha_creacion?->toISOString(),
            'started_at' => $this->fecha_inicio?->toISOString(),
            'finished_at' => $this->fecha_fin?->toISOString(),
            'hearing_type' => $this->whenLoaded('tipoAudiencia', fn (): array => [
                'id' => $this->tipoAudiencia->getKey(),
                'code' => $this->tipoAudiencia->codigo,
                'name' => $this->tipoAudiencia->nombre,
            ]),
            'current_stage' => $this->whenLoaded('etapaActual', fn (): ?array => $this->etapaActual === null ? null : [
                'id' => $this->etapaActual->getKey(),
                'code' => $this->etapaActual->codigo,
                'name' => $this->etapaActual->nombre,
                'is_final' => $this->etapaActual->es_final,
                'allowed_next_stages' => $this->estado !== 'activa' || $this->etapaActual->es_final
                    ? []
                    : $this->etapaActual->transicionesSalientes
                        ->map(fn ($transicion): ?array => $transicion->destino === null ? null : [
                            'id' => $transicion->destino->getKey(),
                            'code' => $transicion->destino->codigo,
                            'name' => $transicion->destino->nombre,
                        ])
                        ->filter()
                        ->values()
                        ->all(),
            ]),
            'participants' => $this->whenLoaded('participantes', fn (): array => $this->participantes
                ->map(fn ($participante): array => [
                    'id' => $participante->getKey(),
                    'role' => $participante->rol,
                    'display_name' => $participante->nombre_mostrado,
                    'controlled_by' => $participante->controlado_por,
                    'status' => $participante->estado,
                ])
                ->values()
                ->all()),
            'interventions' => $this->whenLoaded('intervenciones', fn (): array => $this->intervenciones
                ->map(fn ($intervencion): array => [
                    'id' => $intervencion->getKey(),
                    'order' => $intervencion->orden,
                    'content' => $intervencion->contenido,
                    'input_type' => $intervencion->tipo_entrada,
                    'stage_id' => $intervencion->id_etapa,
                    'created_at' => $intervencion->fecha_intervencion?->toISOString(),
                    'participant' => $intervencion->participante === null ? null : [
                        'id' => $intervencion->participante->getKey(),
                        'role' => $intervencion->participante->rol,
                        'display_name' => $intervencion->participante->nombre_mostrado,
                    ],
                ])
                ->values()
                ->all()),
        ];
    }
}
