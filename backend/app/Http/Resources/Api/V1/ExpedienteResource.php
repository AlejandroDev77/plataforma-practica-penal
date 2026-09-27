<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Expediente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expediente */
final class ExpedienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->titulo,
            'description' => $this->descripcion,
            'case_number' => $this->numero_caso,
            'status' => $this->estado,
            'processing_status' => $this->estado_procesamiento,
            'file_count' => $this->whenCounted('archivos', $this->archivos_count),
            'created_at' => $this->fecha_creacion?->toISOString(),
            'updated_at' => $this->fecha_actualizacion?->toISOString(),
            'files' => ArchivoExpedienteResource::collection($this->whenLoaded('archivos')),
        ];
    }
}
