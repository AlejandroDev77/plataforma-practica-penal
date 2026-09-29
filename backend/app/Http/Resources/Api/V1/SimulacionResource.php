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
        $turnoActual = $this->getAttribute('turno_actual');

        return [
            'id' => $this->getKey(),
            'case_id' => $this->id_expediente,
            'analysis_id' => $this->id_analisis,
            'user_role' => $this->rol_usuario,
            'status' => $this->estado,
            'current_turn' => $turnoActual === null ? null : [
                'configured' => $turnoActual['configurada'],
                'complete' => $turnoActual['completa'],
                'order' => $turnoActual['orden'],
                'role' => $turnoActual['rol'],
                'allowed_actions' => $turnoActual['acciones_permitidas'],
            ],
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
                    'sources' => $intervencion->fuentes
                        ->map(fn ($fuente): array => [
                            'id' => $fuente->getKey(),
                            'fragment_id' => $fuente->id_fragmento,
                            'excerpt' => $fuente->fragmento_utilizado,
                            'source' => [
                                'kind' => $fuente->metadatos['tipo_fuente'] ?? 'desconocida',
                                'title' => $fuente->metadatos['titulo'] ?? null,
                                'category' => $fuente->metadatos['categoria'] ?? null,
                                'identifier' => $fuente->metadatos['numero_norma'] ?? null,
                                'version' => $fuente->metadatos['version'] ?? null,
                                'page' => $fuente->metadatos['numero_pagina'] ?? null,
                                'locator' => $fuente->metadatos['localizador'] ?? null,
                                'valid_from' => $fuente->metadatos['fecha_vigencia'] ?? null,
                                'valid_until' => $fuente->metadatos['fecha_fin_vigencia'] ?? null,
                            ],
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all()),
        ];
    }
}
