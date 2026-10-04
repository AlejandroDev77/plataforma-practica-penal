<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RevocacionRevisionReglas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

final class RevisionReglasAudienciaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $revocacion = RevocacionRevisionReglas::query()->where('id_revision_reglas', $this->getKey())->first();

        return [
            'id' => $this->getKey(), 'id_version_reglas' => $this->id_version_reglas,
            'id_tipo_audiencia' => $this->id_tipo_audiencia, 'id_habilitacion' => $this->id_habilitacion,
            'id_usuario_revisor' => $this->id_usuario_revisor, 'huella_version' => $this->huella_version,
            'decision' => $this->decision, 'alcance_revisado' => $this->alcance_revisado,
            'observacion' => $this->observacion,
            'fuentes_verificadas' => array_map(fn ($fuente) => Arr::only($fuente, ['referencia', 'huella_contenido']), $this->fuentes_verificadas),
            'vigencia_comprobada_en' => $this->vigencia_comprobada_en->toDateString(),
            'confirmacion_humana' => $this->confirmacion_humana,
            'fecha_creacion' => $this->fecha_creacion->toIso8601String(),
            'fecha_vencimiento' => $this->fecha_vencimiento?->toIso8601String(),
            'revocacion' => $revocacion ? [
                'id' => $revocacion->getKey(), 'id_usuario_responsable' => $revocacion->id_usuario_responsable,
                'motivo' => $revocacion->motivo, 'fecha_creacion' => $revocacion->fecha_creacion->toIso8601String(),
            ] : null,
            // El estado vigente se consulta en la version; una decision historica no lo sustituye.
            'execution_authorized' => false,
        ];
    }
}
