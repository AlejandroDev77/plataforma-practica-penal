<?php

namespace App\Services\Simulaciones;

use App\Models\VersionReglasAudiencia;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ConsultarRevisionJuridicaReglas
{
    /** Diagnostico interno, no permiso de ejecucion ni comprobacion de propietario HTTP. */
    public function ejecutar(int $idVersion): array
    {
        // Una vista MVCC: no mezclar decision, habilitacion y revocaciones de momentos distintos.
        $registro = DB::selectOne(<<<'SQL'
            SELECT v.id_version_reglas, r.id_revision_reglas, r.id_habilitacion,
                CASE
                    WHEN r.id_revision_reglas IS NULL THEN 'no_acreditada'
                    WHEN rr.id_revocacion_revision IS NOT NULL THEN 'revision_revocada'
                    WHEN r.decision = 'requiere_cambios' THEN 'requiere_cambios'
                    WHEN rh.id_revocacion_habilitacion IS NOT NULL THEN 'habilitacion_revocada'
                    WHEN h.fecha_vencimiento <= clock_timestamp() THEN 'habilitacion_vencida'
                    WHEN r.fecha_vencimiento <= clock_timestamp() THEN 'revision_vencida'
                    ELSE 'aprobado'
                END AS estado
            FROM versiones_reglas_audiencia v
            LEFT JOIN LATERAL (SELECT * FROM revisiones_reglas_audiencia
                WHERE id_version_reglas = v.id_version_reglas
                ORDER BY fecha_creacion DESC, id_revision_reglas DESC LIMIT 1) r ON true
            LEFT JOIN habilitaciones_revision_juridica h ON h.id_habilitacion = r.id_habilitacion
            LEFT JOIN revocaciones_habilitacion_juridica rh ON rh.id_habilitacion = h.id_habilitacion
            LEFT JOIN revocaciones_revision_reglas rr ON rr.id_revision_reglas = r.id_revision_reglas
            WHERE v.id_version_reglas = ?
            SQL, [$idVersion]);
        if ($registro === null) {
            throw (new ModelNotFoundException)->setModel(VersionReglasAudiencia::class, [$idVersion]);
        }

        return [
            'version_id' => $idVersion,
            'review_id' => $registro->id_revision_reglas,
            'qualification_id' => $registro->id_habilitacion,
            'legal_review_status' => $registro->estado,
            'review_approved' => $registro->estado === 'aprobado',
            'execution_authorized' => false,
            'requires_separate_activation' => true,
        ];
    }
}
