<?php

namespace App\Services\Simulaciones;

use App\Models\TipoAudiencia;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CapturarBorradorReglas
{
    /** Una vista MVCC para el contenido completo y la última versión del tipo. Sin escrituras. */
    public function ejecutar(int $idTipoAudiencia): array
    {
        $fila = DB::selectOne(<<<'SQL'
            WITH copia AS (
                SELECT jsonb_build_object(
                    'formato', 1,
                    'tipo', to_jsonb(t),
                    'etapas', COALESCE((SELECT jsonb_agg(to_jsonb(e) ORDER BY e.orden, e.id_etapa)
                        FROM etapas_audiencia e WHERE e.id_tipo_audiencia = t.id_tipo_audiencia), '[]'::jsonb),
                    'transiciones', COALESCE((SELECT jsonb_agg(to_jsonb(tr) ORDER BY tr.id_etapa_origen, tr.id_etapa_destino)
                        FROM transiciones_audiencia tr WHERE tr.id_tipo_audiencia = t.id_tipo_audiencia), '[]'::jsonb),
                    'propuestas', COALESCE((SELECT jsonb_agg(to_jsonb(p) ORDER BY p.id_etapa, p.orden)
                        FROM propuestas_turnos_audiencia p WHERE p.id_tipo_audiencia = t.id_tipo_audiencia), '[]'::jsonb),
                    'turnos_catalogo_referencia', COALESCE((SELECT jsonb_agg(to_jsonb(tc) ORDER BY tc.id_etapa, tc.orden)
                        FROM turnos_etapa_audiencia tc WHERE tc.id_tipo_audiencia = t.id_tipo_audiencia), '[]'::jsonb)
                ) AS contenido
                FROM tipos_audiencia t WHERE t.id_tipo_audiencia = ?
            ), borrador AS (
                SELECT contenido, jsonb_build_object(
                    'contenido', contenido,
                    'id_ultima_version', (SELECT MAX(id_version_reglas) FROM versiones_reglas_audiencia
                        WHERE id_tipo_audiencia = ?)
                ) AS precondicion FROM copia
            )
            SELECT contenido, octet_length(contenido::text) AS bytes,
                encode(sha256(convert_to(precondicion::text, 'UTF8')), 'hex') AS huella_borrador
            FROM borrador
            SQL, [$idTipoAudiencia, $idTipoAudiencia]);
        if (! $fila) {
            throw (new ModelNotFoundException)->setModel(TipoAudiencia::class, [$idTipoAudiencia]);
        }

        return ['contenido' => json_decode($fila->contenido, true, flags: JSON_THROW_ON_ERROR),
            'bytes' => (int) $fila->bytes, 'huella_borrador' => $fila->huella_borrador];
    }
}
