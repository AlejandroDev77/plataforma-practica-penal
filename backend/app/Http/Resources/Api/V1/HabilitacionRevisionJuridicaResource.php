<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

final class HabilitacionRevisionJuridicaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Estado y retiro bajo una misma vista MVCC, no dos consultas separadas.
        $estado = DB::table('habilitaciones_revision_juridica as h')
            ->leftJoin('revocaciones_habilitacion_juridica as r', 'r.id_habilitacion', '=', 'h.id_habilitacion')
            ->where('h.id_habilitacion', $this->getKey())
            ->select(['r.id_revocacion_habilitacion', 'r.id_usuario_responsable', 'r.motivo', 'r.fecha_creacion as fecha_revocacion'])
            ->selectRaw("CASE WHEN r.id_revocacion_habilitacion IS NOT NULL THEN 'revocada'
                WHEN h.fecha_vencimiento <= clock_timestamp() THEN 'vencida' ELSE 'vigente' END as estado")
            ->first();

        return [
            'id' => $this->getKey(), 'id_usuario_revisor' => $this->id_usuario_revisor,
            'id_usuario_habilitador' => $this->id_usuario_habilitador, 'id_tipo_audiencia' => $this->id_tipo_audiencia,
            'alcance' => $this->alcance, 'referencia_acreditacion' => $this->referencia_acreditacion,
            'huella_acreditacion' => $this->huella_acreditacion, 'acreditacion_verificada' => $this->acreditacion_verificada,
            'fecha_creacion' => $this->fecha_creacion->toIso8601String(),
            'fecha_vencimiento' => $this->fecha_vencimiento->toIso8601String(),
            'estado' => $estado->estado,
            'revocacion' => $estado->id_revocacion_habilitacion !== null ? [
                'id' => $estado->id_revocacion_habilitacion, 'id_usuario_responsable' => $estado->id_usuario_responsable,
                'motivo' => $estado->motivo, 'fecha_creacion' => CarbonImmutable::parse($estado->fecha_revocacion)->toIso8601String(),
            ] : null,
            'execution_authorized' => false,
        ];
    }
}
