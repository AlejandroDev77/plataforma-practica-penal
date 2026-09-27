<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaginaExpediente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaginaExpediente */
final class PaginaExpedienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'page_number' => $this->numero_pagina,
            'locator' => $this->localizador,
            'text' => $this->texto_extraido,
            'used_ocr' => $this->uso_ocr,
            'confidence' => $this->nivel_confianza === null ? null : (float) $this->nivel_confianza,
            'is_readable' => $this->es_legible,
        ];
    }
}
