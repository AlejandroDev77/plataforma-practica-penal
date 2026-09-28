<?php

namespace App\Services\Simulaciones;

use App\Models\Intervencion;
use App\Models\ParticipanteSimulacion;
use App\Models\Simulacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegistrarIntervencion
{
    public function __construct(private readonly ResolverTurnoAudiencia $turnos) {}

    public function ejecutar(Simulacion $simulacion, string $contenido): Simulacion
    {
        return DB::transaction(function () use ($simulacion, $contenido): Simulacion {
            $actual = Simulacion::query()
                ->whereKey($simulacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== 'activa' || $actual->id_etapa_actual === null) {
                throw ValidationException::withMessages([
                    'simulacion' => 'Solo se puede intervenir en una audiencia activa.',
                ]);
            }

            $turnos = $this->turnos->turnosConfigurados($actual, (int) $actual->id_etapa_actual);
            $cantidadIntervenciones = $actual->intervenciones()
                ->where('id_etapa', $actual->id_etapa_actual)
                ->count();
            $turno = $turnos[$cantidadIntervenciones] ?? null;

            if ($turno === null) {
                throw ValidationException::withMessages([
                    'contenido' => $turnos === []
                        ? 'Esta etapa todavía no tiene turnos aprobados para intervenir.'
                        : 'Ya se completaron los turnos configurados para esta etapa.',
                ]);
            }

            if ($turno['rol'] !== $actual->rol_usuario) {
                throw ValidationException::withMessages([
                    'contenido' => 'El siguiente turno está asignado a otro rol.',
                ]);
            }

            $participante = ParticipanteSimulacion::query()
                ->where('id_simulacion', $actual->getKey())
                ->where('rol', $turno['rol'])
                ->where('controlado_por', 'usuario')
                ->where('estado', 'activo')
                ->orderBy('id_participante_simulacion')
                ->first();

            if ($participante === null) {
                throw ValidationException::withMessages([
                    'contenido' => 'No hay un participante activo del usuario para el rol del turno actual.',
                ]);
            }

            $orden = (int) ($actual->intervenciones()->max('orden') ?? 0) + 1;

            Intervencion::query()->create([
                'id_simulacion' => $actual->getKey(),
                'id_participante_simulacion' => $participante->getKey(),
                'id_tipo_audiencia' => $actual->id_tipo_audiencia,
                'id_etapa' => $actual->id_etapa_actual,
                'orden' => $orden,
                'contenido' => trim($contenido),
                'tipo_entrada' => 'texto',
            ]);

            return $actual->fresh();
        }, 3);
    }
}
