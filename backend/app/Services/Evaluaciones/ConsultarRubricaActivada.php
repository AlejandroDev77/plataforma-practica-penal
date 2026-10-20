<?php

namespace App\Services\Evaluaciones;

use Illuminate\Support\Facades\DB;

/** Uso interno tras autorizar la práctica. No reserva vigencia ni entrega textos al cliente. */
final class ConsultarRubricaActivada
{
    public function ejecutar(int $idTipo, string $rol): ?array
    {
        if ($rol !== 'abogado_defensor') {
            return null;
        }

        return DB::transaction(fn () => $this->consultar($idTipo));
    }

    private function consultar(int $idTipo): ?array
    {
        DB::selectOne('SELECT id_tipo_audiencia FROM tipos_audiencia WHERE id_tipo_audiencia=? FOR NO KEY UPDATE', [$idTipo]);
        $ultima = DB::table('activaciones_rubricas')->where('id_tipo_audiencia', $idTipo)->orderByDesc('id_activacion_rubrica')->first(['id_rubrica']);
        if ($ultima === null) {
            return null;
        }
        DB::selectOne('SELECT id_rubrica FROM rubricas WHERE id_rubrica=? FOR UPDATE', [$ultima->id_rubrica]);
        $fila = DB::selectOne(<<<'SQL'
            SELECT a.id_activacion_rubrica, a.id_version_rubrica, a.id_revision_rubrica, a.id_rubrica,
                v.contenido, jurissim_estado_activacion_rubrica(a.id_activacion_rubrica) estado_activacion,
                jurissim_estado_revision_rubrica(a.id_version_rubrica) estado_revision
            FROM activaciones_rubricas a JOIN versiones_rubricas_evaluacion v USING (id_version_rubrica)
            WHERE a.id_tipo_audiencia=? ORDER BY a.id_activacion_rubrica DESC LIMIT 1
            SQL, [$idTipo]);
        if ($fila === null) {
            return null;
        }
        $resultado = ['estado_activacion' => $fila->estado_activacion, 'estado_revision' => $fila->estado_revision,
            'rubrica' => null, 'caracteres_criterios' => 0];
        if ($fila->estado_activacion !== 'vigente' || $fila->estado_revision !== 'aprobado') {
            return $resultado;
        }
        $copia = json_decode($fila->contenido, true, 512, JSON_THROW_ON_ERROR);
        $caracteres = 0;
        foreach ($copia['criterios'] as $c) {
            $caracteres += mb_strlen($c['nombre'], 'UTF-8') + mb_strlen($c['descripcion'], 'UTF-8');
        }

        return [...$resultado, 'rubrica' => ['id_rubrica' => (int) $fila->id_rubrica,
            'version' => $copia['rubrica']['version_catalogo'], 'criterion_count' => count($copia['criterios']),
            'id_version_rubrica' => (int) $fila->id_version_rubrica,
            'id_revision_rubrica' => (int) $fila->id_revision_rubrica,
            'id_activacion_rubrica' => (int) $fila->id_activacion_rubrica], 'caracteres_criterios' => $caracteres];
    }
}
