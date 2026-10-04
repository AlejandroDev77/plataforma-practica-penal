<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RevocacionActivacionReglas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ActivacionReglasAudienciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $retiro = RevocacionActivacionReglas::query()->where('id_activacion', $this->getKey())->first();

        return [
            'id' => $this->getKey(), 'id_tipo_audiencia' => $this->id_tipo_audiencia,
            'id_version_reglas' => $this->id_version_reglas, 'id_revision_reglas' => $this->id_revision_reglas,
            'id_usuario_responsable' => $this->id_usuario_responsable,
            'huella_version' => $this->huella_version, 'id_activacion_previa' => $this->id_activacion_previa,
            'motivo' => $this->motivo, 'fecha_creacion' => $this->fecha_creacion->toIso8601String(),
            'revocacion' => $retiro ? [
                'id' => $retiro->getKey(), 'id_usuario_responsable' => $retiro->id_usuario_responsable,
                'motivo' => $retiro->motivo, 'fecha_creacion' => $retiro->fecha_creacion->toIso8601String(),
            ] : null,
            // Historial, no estado vigente ni permiso para ejecutar.
            'execution_authorized' => false, 'requires_simulation_binding' => true,
        ];
    }
}
