<?php

namespace App\Services\Analisis;

use App\Models\AnalisisExpediente;
use App\Models\PaginaExpediente;
use App\Models\RevisionAnalisis;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegistrarRevisionAnalisis
{
    public function ejecutar(AnalisisExpediente $analisis, User $revisor, string $decision, ?string $observacion): RevisionAnalisis
    {
        return DB::transaction(function () use ($analisis, $revisor, $decision, $observacion): RevisionAnalisis {
            $analisis = AnalisisExpediente::query()
                ->whereKey($analisis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($decision === 'aprobado') {
                $this->validarCitasDisponibles($analisis);
            }

            return RevisionAnalisis::query()->create([
                'id_expediente' => $analisis->id_expediente,
                'id_analisis' => $analisis->getKey(),
                'id_usuario' => $revisor->getKey(),
                'decision' => $decision,
                'observacion' => $observacion,
            ]);
        }, 3);
    }

    private function validarCitasDisponibles(AnalisisExpediente $analisis): void
    {
        $resultado = $analisis->datos_estructurados ?? [];
        $citas = CitasAnalisis::recopilar($resultado);

        if ($citas === []) {
            throw ValidationException::withMessages([
                'decision' => 'No se puede aprobar un análisis sin citas verificables.',
            ]);
        }

        $idsPagina = collect($citas)->pluck('page_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $paginas = PaginaExpediente::query()
            ->whereIn('id_pagina', $idsPagina)
            ->whereHas('archivo', fn ($query) => $query->where('id_expediente', $analisis->id_expediente))
            ->get()
            ->keyBy('id_pagina');

        foreach ($citas as $cita) {
            $pagina = $paginas->get((int) $cita['page_id']);

            if ($pagina === null
                || ! $pagina->es_legible
                || ! is_string($pagina->texto_extraido)
                || ! CitasAnalisis::coincide($pagina->texto_extraido, $cita['excerpt'])) {
                throw ValidationException::withMessages([
                    'decision' => 'No se puede aprobar: una o más citas ya no están disponibles o no coinciden con su página.',
                ]);
            }
        }
    }
}
