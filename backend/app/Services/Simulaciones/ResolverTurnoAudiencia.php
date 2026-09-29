<?php

namespace App\Services\Simulaciones;

use App\Models\Simulacion;
use App\Models\TurnoEtapaAudiencia;
use DomainException;

final class ResolverTurnoAudiencia
{
    /** @return array<int, array{orden: int, rol: string, instruccion?: string|null}> */
    public function turnosConfigurados(Simulacion $simulacion, int $idEtapa): array
    {
        $configuracion = $simulacion->configuracion ?? [];
        $turnosPorEtapa = $configuracion['turnos_por_etapa'] ?? [];
        $snapshot = $turnosPorEtapa[(string) $idEtapa] ?? $turnosPorEtapa[$idEtapa] ?? [];

        return array_values(array_filter($snapshot, fn ($turno): bool => is_array($turno)
            && isset($turno['orden'], $turno['rol'])
            && is_numeric($turno['orden'])
            && is_string($turno['rol'])
        ));
    }

    /**
     * @return array{
     *     configurada: bool,
     *     completa: bool,
     *     orden: int|null,
     *     rol: string|null,
     *     acciones_permitidas: array<int, string>
     * }
     */
    public function resumir(Simulacion $simulacion, int $intervencionesEnEtapa): array
    {
        $turnos = $simulacion->id_etapa_actual === null
            ? []
            : $this->turnosConfigurados($simulacion, (int) $simulacion->id_etapa_actual);
        $siguiente = $turnos[$intervencionesEnEtapa] ?? null;
        $rol = $siguiente['rol'] ?? null;
        $puedeIntervenir = $simulacion->estado === 'activa'
            && $rol !== null
            && $rol === $simulacion->rol_usuario;

        return [
            'configurada' => $turnos !== [],
            'completa' => $turnos !== [] && $intervencionesEnEtapa >= count($turnos),
            'orden' => isset($siguiente['orden']) ? (int) $siguiente['orden'] : null,
            'rol' => $rol,
            'acciones_permitidas' => $puedeIntervenir ? ['submit_text_intervention'] : [],
        ];
    }

    public function exigirTurnosCompletados(Simulacion $simulacion, int $idEtapa): void
    {
        $turnos = $this->turnosConfigurados($simulacion, $idEtapa);

        // Las etapas sin una política jurídica aprobada conservan el avance de catálogo,
        // pero no aceptan intervenciones de usuario.
        if ($turnos === []) {
            return;
        }

        $registradas = $simulacion->intervenciones()
            ->where('id_etapa', $idEtapa)
            ->count();

        if ($registradas < count($turnos)) {
            throw new DomainException('Deben completarse los turnos configurados antes de avanzar de etapa.');
        }
    }

    /** @return array<string, array<int, array{orden: int, rol: string, instruccion: string|null}>> */
    public function capturarConfiguracion(int $idTipoAudiencia): array
    {
        return TurnoEtapaAudiencia::query()
            ->where('id_tipo_audiencia', $idTipoAudiencia)
            ->where('activo', true)
            ->whereHas('etapa', fn ($query) => $query->where('activo', true))
            ->orderBy('id_etapa')
            ->orderBy('orden')
            ->get(['id_etapa', 'orden', 'rol', 'instruccion'])
            ->groupBy('id_etapa')
            ->map(fn ($turnos): array => $turnos->map(fn (TurnoEtapaAudiencia $turno): array => [
                'orden' => $turno->orden,
                'rol' => $turno->rol,
                'instruccion' => $turno->instruccion,
            ])->values()->all())
            ->all();
    }
}
