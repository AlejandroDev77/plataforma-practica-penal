<?php

namespace App\Services\Simulaciones;

use App\Models\Expediente;
use App\Models\ParticipanteSimulacion;
use App\Models\Simulacion;
use App\Models\TipoAudiencia;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrearSimulacion
{
    public function __construct(
        private readonly AvanzarEtapaAudiencia $avanzarEtapa,
        private readonly ResolverTurnoAudiencia $turnos,
    ) {}

    public function ejecutar(User $usuario, Expediente $expediente, int $idAnalisis, int $idTipoAudiencia): Simulacion
    {
        return DB::transaction(function () use ($usuario, $expediente, $idAnalisis, $idTipoAudiencia): Simulacion {
            $caso = Expediente::query()
                ->whereKey($expediente->getKey())
                ->where('id_usuario', $usuario->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $analisis = $caso->analisis()
                ->whereKey($idAnalisis)
                ->with('revisiones')
                ->lockForUpdate()
                ->firstOrFail();

            if ($analisis->revisiones->first()?->decision !== 'aprobado') {
                throw ValidationException::withMessages([
                    'id_analisis' => 'La simulación requiere una versión del análisis aprobada por una persona.',
                ]);
            }

            $tipoAudiencia = TipoAudiencia::query()
                ->whereKey($idTipoAudiencia)
                ->where('activo', true)
                ->first();

            if ($tipoAudiencia === null) {
                throw ValidationException::withMessages([
                    'id_tipo_audiencia' => 'El tipo de audiencia no existe o no está habilitado.',
                ]);
            }

            $turnosPorEtapa = $this->turnos->capturarConfiguracion($tipoAudiencia->getKey());
            $simulacion = $usuario->simulaciones()->create([
                'id_expediente' => $caso->getKey(),
                'id_analisis' => $analisis->getKey(),
                'id_tipo_audiencia' => $tipoAudiencia->getKey(),
                'rol_usuario' => 'abogado_defensor',
                'estado' => 'preparando',
                'configuracion' => [
                    'turnos_por_etapa' => $turnosPorEtapa,
                ],
            ]);

            ParticipanteSimulacion::query()->create([
                'id_simulacion' => $simulacion->getKey(),
                'id_analisis' => $analisis->getKey(),
                'id_expediente' => $caso->getKey(),
                'rol' => 'abogado_defensor',
                'nombre_mostrado' => $usuario->name,
                'controlado_por' => 'usuario',
                'estado' => 'activo',
            ]);

            $rolesAgente = collect($turnosPorEtapa)
                ->flatten(1)
                ->pluck('rol')
                ->filter(fn (string $rol): bool => in_array($rol, ['juez', 'fiscal'], true))
                ->unique();

            foreach ($rolesAgente as $rol) {
                ParticipanteSimulacion::query()->create([
                    'id_simulacion' => $simulacion->getKey(),
                    'id_analisis' => $analisis->getKey(),
                    'id_expediente' => $caso->getKey(),
                    'rol' => $rol,
                    'nombre_mostrado' => $rol === 'juez' ? 'Juez simulado' : 'Fiscal simulado',
                    'controlado_por' => 'ia',
                    'estado' => 'activo',
                ]);
            }

            try {
                $simulacion = $this->avanzarEtapa->ejecutar($simulacion);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages([
                    'id_tipo_audiencia' => $exception->getMessage(),
                ]);
            }

            return $simulacion;
        }, 3);
    }
}
