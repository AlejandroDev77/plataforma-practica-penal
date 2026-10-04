<?php

namespace App\Services\Simulaciones;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ConsultarActivacionReglas
{
    /** Consulta administrativa, no reserva ni permiso para ejecutar una intervencion. */
    public function ejecutar(User $actor, int $idTipo): array
    {
        if (! User::query()->findOrFail($actor->getKey())->hasRole('administrador_plataforma')) {
            throw new AuthorizationException('Consulta de activacion reservada a administracion de plataforma.');
        }
        $fila = DB::selectOne(<<<'SQL'
            SELECT a.id_activacion, a.id_version_reglas, a.id_revision_reglas, a.huella_version,
                CASE
                    WHEN ra.id_revocacion_activacion IS NOT NULL THEN 'retirada'
                    WHEN ultima.id_revision_reglas IS DISTINCT FROM a.id_revision_reglas THEN 'revision_sustituida'
                    WHEN rr.id_revocacion_revision IS NOT NULL THEN 'revision_retirada'
                    WHEN rh.id_revocacion_habilitacion IS NOT NULL THEN 'habilitacion_retirada'
                    WHEN h.fecha_vencimiento <= clock_timestamp() THEN 'habilitacion_vencida'
                    WHEN r.fecha_vencimiento <= clock_timestamp() THEN 'revision_vencida'
                    WHEN NOT t.activo THEN 'tipo_inactivo'
                    WHEN a.huella_version <> v.huella_contenido
                        OR v.huella_contenido <> encode(sha256(convert_to(v.contenido::text,'UTF8')),'hex')
                        OR r.huella_ejecucion_revisada IS DISTINCT FROM v.huella_contenido THEN 'integridad_incompatible'
                    ELSE 'vigente'
                END AS estado
            FROM activaciones_reglas_audiencia a
            JOIN versiones_reglas_audiencia v ON v.id_version_reglas=a.id_version_reglas
            JOIN revisiones_reglas_audiencia r ON r.id_revision_reglas=a.id_revision_reglas
            JOIN habilitaciones_revision_juridica h ON h.id_habilitacion=a.id_habilitacion
            JOIN tipos_audiencia t ON t.id_tipo_audiencia=a.id_tipo_audiencia
            LEFT JOIN LATERAL (SELECT id_revision_reglas FROM revisiones_reglas_audiencia
                WHERE id_version_reglas=v.id_version_reglas ORDER BY fecha_creacion DESC,id_revision_reglas DESC LIMIT 1) ultima ON true
            LEFT JOIN revocaciones_activaciones_reglas ra ON ra.id_activacion=a.id_activacion
            LEFT JOIN revocaciones_revision_reglas rr ON rr.id_revision_reglas=r.id_revision_reglas
            LEFT JOIN revocaciones_habilitacion_juridica rh ON rh.id_habilitacion=h.id_habilitacion
            WHERE a.id_tipo_audiencia=? ORDER BY a.id_activacion DESC LIMIT 1
            SQL, [$idTipo]);

        return ['activation_id' => $fila?->id_activacion, 'version_id' => $fila?->id_version_reglas,
            'review_id' => $fila?->id_revision_reglas, 'huella_version' => $fila?->huella_version,
            'activation_status' => $fila?->estado ?? 'sin_activacion', 'activation_current' => $fila?->estado === 'vigente',
            'read_only' => true, 'execution_authorized' => false, 'requires_simulation_binding' => true];
    }
}
