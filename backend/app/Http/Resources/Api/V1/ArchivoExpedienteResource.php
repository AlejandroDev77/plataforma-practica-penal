<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ArchivoExpediente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ArchivoExpediente */
final class ArchivoExpedienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->nombre_original,
            'mime_type' => $this->tipo_mime,
            'extension' => $this->extension,
            'size_bytes' => $this->tamano_bytes,
            'page_count' => $this->cantidad_paginas,
            'requires_ocr' => $this->requiere_ocr,
            'processing_status' => $this->estado_procesamiento,
            'created_at' => $this->fecha_creacion?->toISOString(),
        ];
    }
}
