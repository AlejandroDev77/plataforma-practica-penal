<?php

namespace App\Services\Simulaciones;

use App\Models\EtapaAudiencia;
use App\Models\Simulacion;
use App\Models\TransicionAudiencia;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AvanzarEtapaAudiencia
{
    public function ejecutar(Simulacion $simulacion, ?int $destinoSolicitado = null): Simulacion
    {
        return DB::transaction(function () use ($simulacion, $destinoSolicitado): Simulacion {
            $actual = Simulacion::query()
                ->whereKey($simulacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado === 'preparando') {
                return $this->iniciar($actual, $destinoSolicitado);
            }

            if ($actual->estado !== 'activa' || $actual->id_etapa_actual === null) {
                throw new DomainException('Solo se puede avanzar una audiencia activa.');
            }

            $etapa = EtapaAudiencia::query()
                ->whereKey($actual->id_etapa_actual)
                ->where('id_tipo_audiencia', $actual->id_tipo_audiencia)
                ->where('activo', true)
                ->first();

            if ($etapa === null) {
                throw new DomainException(
                    'La etapa actual no pertenece a esta audiencia o está inactiva.',
                );
            }

            if ($etapa->es_final) {
                if ($destinoSolicitado !== null) {
                    throw new DomainException('La etapa final no admite un destino posterior.');
                }

                $actual->forceFill([
                    'estado' => 'finalizada',
                    'fecha_fin' => now(),
                ])->save();

                return $actual->fresh(['etapaActual']);
            }

            $transiciones = TransicionAudiencia::query()
                ->with('destino')
                ->where('id_tipo_audiencia', $actual->id_tipo_audiencia)
                ->where('id_etapa_origen', $etapa->getKey())
                ->where('activo', true)
                ->whereHas('destino', fn ($query) => $query->where('activo', true))
                ->get();

            if ($transiciones->isEmpty()) {
                throw new DomainException('La etapa no tiene transiciones activas configuradas.');
            }

            if ($destinoSolicitado === null && $transiciones->count() > 1) {
                throw new DomainException(
                    'La etapa tiene varias salidas; debe indicar el destino permitido.',
                );
            }

            $transicion = $destinoSolicitado === null
                ? $transiciones->first()
                : $transiciones->firstWhere('id_etapa_destino', $destinoSolicitado);

            if ($transicion === null) {
                throw new DomainException(
                    'El destino solicitado no está permitido desde la etapa actual.',
                );
            }

            $actual->forceFill([
                'id_etapa_actual' => $transicion->id_etapa_destino,
            ])->save();

            return $actual->fresh(['etapaActual']);
        }, 3);
    }

    private function iniciar(Simulacion $simulacion, ?int $destinoSolicitado): Simulacion
    {
        if ($destinoSolicitado !== null || $simulacion->id_etapa_actual !== null) {
            throw new DomainException(
                'Una audiencia nueva siempre inicia en su etapa inicial configurada.',
            );
        }

        $etapasIniciales = EtapaAudiencia::query()
            ->where('id_tipo_audiencia', $simulacion->id_tipo_audiencia)
            ->where('es_inicial', true)
            ->where('activo', true)
            ->get();

        if ($etapasIniciales->count() !== 1) {
            throw new DomainException(
                'El tipo de audiencia debe tener exactamente una etapa inicial activa.',
            );
        }

        $simulacion->forceFill([
            'id_etapa_actual' => $etapasIniciales->first()->getKey(),
            'estado' => 'activa',
            'fecha_inicio' => $simulacion->fecha_inicio ?? now(),
        ])->save();

        return $simulacion->fresh(['etapaActual']);
    }
}
