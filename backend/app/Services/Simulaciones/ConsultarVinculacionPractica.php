<?php

namespace App\Services\Simulaciones;

use App\Models\Simulacion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class ConsultarVinculacionPractica
{
    /** Consulta privada sin instrucciones, no reserva de turno ni permiso de ejecucion. */
    public function ejecutar(User $actor, int $idSimulacion): array
    {
        $simulacion = $actor->simulaciones()->whereKey($idSimulacion)
            ->whereHas('expediente', fn ($q) => $q->where('id_usuario', $actor->getKey()))->firstOrFail();
        $fila = DB::selectOne(<<<'SQL'
            SELECT l.id_vinculacion, l.id_activacion, l.id_version_reglas, l.huella_version,
                CASE
                    WHEN l.id_vinculacion IS NULL THEN 'sin_vinculacion'
                    WHEN l.id_tipo_audiencia <> s.id_tipo_audiencia OR l.id_usuario_responsable <> s.id_usuario
                        OR s.rol_usuario <> 'abogado_defensor'
                        OR l.huella_version <> v.huella_contenido
                        OR NOT jurissim_contrato_ejecucion_valido(v.contenido, l.id_tipo_audiencia)
                        THEN 'integridad_incompatible'
                    WHEN s.configuracion IS DISTINCT FROM jurissim_configuracion_practica(v.contenido) THEN 'integridad_incompatible'
                    WHEN an.estado <> 'procesado' OR ultima.decision IS DISTINCT FROM 'aprobado' THEN 'analisis_no_aprobado'
                    ELSE jurissim_estado_activacion_practica(l.id_activacion)
                END estado
            FROM simulaciones s
            JOIN analisis_expediente an ON an.id_analisis=s.id_analisis
            LEFT JOIN vinculaciones_reglas_practica l ON l.id_simulacion=s.id_simulacion
            LEFT JOIN versiones_reglas_audiencia v ON v.id_version_reglas=l.id_version_reglas
            LEFT JOIN LATERAL (SELECT decision FROM revisiones_analisis WHERE id_analisis=s.id_analisis
                ORDER BY fecha_creacion DESC,id_revision DESC LIMIT 1) ultima ON true
            WHERE s.id_simulacion=? AND s.id_usuario=?
                AND EXISTS (SELECT 1 FROM expedientes e WHERE e.id_expediente=s.id_expediente AND e.id_usuario=?)
            SQL, [$simulacion->getKey(), $actor->getKey(), $actor->getKey()]);
        if ($fila === null) {
            throw (new ModelNotFoundException)->setModel(Simulacion::class, [$idSimulacion]);
        }

        return ['simulation_id' => $idSimulacion, 'binding_id' => $fila->id_vinculacion,
            'activation_id' => $fila->id_activacion, 'version_id' => $fila->id_version_reglas,
            'huella_version' => $fila->huella_version, 'binding_status' => $fila->estado,
            'binding_current' => $fila->estado === 'vigente', 'read_only' => true,
            'execution_authorized' => false, 'requires_command_integration' => true];
    }
}
