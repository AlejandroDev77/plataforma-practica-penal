<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AnalisisExpediente;
use App\Models\PaginaExpediente;
use App\Services\Analisis\CitasAnalisis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnalisisExpediente */
final class AnalisisExpedienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resultado = $this->datos_estructurados ?? [];
        $idsPagina = collect(CitasAnalisis::recopilar($resultado))
            ->pluck('page_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $paginas = PaginaExpediente::query()
            ->with('archivo')
            ->whereIn('id_pagina', $idsPagina)
            ->whereHas('archivo', fn ($query) => $query->where('id_expediente', $this->id_expediente))
            ->get()
            ->keyBy('id_pagina');
        $revisiones = $this->revisiones ?? collect();
        $actual = $revisiones->first();

        return [
            'id' => $this->getKey(),
            'version' => $this->version,
            'status' => $this->estado,
            'created_at' => $this->fecha_analisis?->toISOString(),
            'review_state' => $actual?->decision ?? 'pendiente',
            'summary' => $this->hallazgo($resultado['summary'] ?? null, 'sources', $paginas),
            'procedural_stage' => $this->hallazgo($resultado['procedural_stage'] ?? null, 'sources', $paginas),
            'participants' => $this->hallazgos($resultado['participants'] ?? [], 'sources', $paginas),
            'offenses' => $this->hallazgos($resultado['offenses'] ?? [], 'sources', $paginas),
            'facts' => $this->hallazgos($resultado['facts'] ?? [], 'sources', $paginas),
            'evidence' => $this->hallazgos($resultado['evidence'] ?? [], 'sources', $paginas),
            'chronology' => $this->hallazgos($resultado['chronology'] ?? [], 'sources', $paginas),
            'missing_information' => $this->hallazgos($resultado['missing_information'] ?? [], 'context_sources', $paginas),
            'uncertainties' => $this->hallazgos($resultado['uncertainties'] ?? [], 'sources', $paginas),
            'reviews' => $revisiones->map(fn ($revision): array => [
                'decision' => $revision->decision,
                'observation' => $revision->observacion,
                'reviewed_at' => $revision->fecha_creacion?->toISOString(),
                'reviewer_name' => $revision->usuario?->name ?? 'Cuenta eliminada',
            ])->values()->all(),
        ];
    }

    private function hallazgos(array $elementos, string $claveFuentes, $paginas): array
    {
        return array_map(fn (array $elemento): array => $this->hallazgo($elemento, $claveFuentes, $paginas) ?? [], $elementos);
    }

    private function hallazgo(?array $elemento, string $claveFuentes, $paginas): ?array
    {
        if ($elemento === null) {
            return null;
        }

        $citas = $elemento[$claveFuentes] ?? [];
        unset($elemento['sources'], $elemento['context_sources']);

        return [
            ...$elemento,
            $claveFuentes => array_map(function (array $cita) use ($paginas): array {
                $pagina = $paginas->get((int) $cita['page_id']);
                $disponible = $pagina !== null
                    && $pagina->es_legible
                    && is_string($pagina->texto_extraido)
                    && CitasAnalisis::coincide($pagina->texto_extraido, $cita['excerpt']);

                return [
                    'file_name' => $disponible ? $pagina->archivo->nombre_original : null,
                    'page_number' => $pagina?->numero_pagina,
                    'locator' => $pagina?->localizador,
                    'excerpt' => $disponible ? $cita['excerpt'] : null,
                    'available' => $disponible,
                ];
            }, $citas),
        ];
    }
}
