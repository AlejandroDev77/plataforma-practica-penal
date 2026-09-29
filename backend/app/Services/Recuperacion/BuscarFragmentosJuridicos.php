<?php

namespace App\Services\Recuperacion;

use Illuminate\Support\Facades\DB;

final class BuscarFragmentosJuridicos
{
    /** @return array{resultados: list<array<string, mixed>>, cantidad_fuentes: int, cantidad_fragmentos: int} */
    public function ejecutar(string $consulta, int $limite = 5): array
    {
        $hoy = today()->toDateString();
        $fuentes = DB::table('fragmentos_documento as fragmento')
            ->join('fuentes_juridicas as fuente', 'fuente.id_fuente', '=', 'fragmento.id_fuente_juridica')
            ->where('fuente.validada', true)
            ->where('fuente.estado', 'vigente')
            ->whereNotNull('fragmento.id_fuente_juridica')
            ->whereNull('fragmento.id_archivo')
            ->whereNotNull('fuente.fecha_vigencia')
            ->whereDate('fuente.fecha_vigencia', '<=', $hoy)
            ->where(fn ($query) => $query
                ->whereNull('fuente.fecha_fin_vigencia')
                ->orWhereDate('fuente.fecha_fin_vigencia', '>=', $hoy))
            ->whereRaw("fragmento.metadatos->>'md5_contenido_fuente' = md5(COALESCE(fuente.contenido_original, ''))")
            ->distinct()
            ->count('fuente.id_fuente');

        $cantidadFragmentos = DB::table('fragmentos_documento as fragmento')
            ->join('fuentes_juridicas as fuente', 'fuente.id_fuente', '=', 'fragmento.id_fuente_juridica')
            ->where('fuente.validada', true)
            ->where('fuente.estado', 'vigente')
            ->whereNotNull('fuente.fecha_vigencia')
            ->whereDate('fuente.fecha_vigencia', '<=', $hoy)
            ->where(fn ($query) => $query
                ->whereNull('fuente.fecha_fin_vigencia')
                ->orWhereDate('fuente.fecha_fin_vigencia', '>=', $hoy))
            ->whereRaw("fragmento.metadatos->>'md5_contenido_fuente' = md5(COALESCE(fuente.contenido_original, ''))")
            ->count();

        $filas = DB::select(<<<'SQL'
            SELECT
                fragmento.id_fragmento,
                fragmento.contenido,
                fuente.titulo,
                fuente.tipo_fuente,
                fuente.numero_norma,
                fuente.version,
                fuente.fecha_vigencia,
                fuente.fecha_fin_vigencia,
                ts_rank_cd(
                    to_tsvector('spanish', fragmento.contenido),
                    consulta.query,
                    32
                ) AS relevancia
            FROM fragmentos_documento AS fragmento
            INNER JOIN fuentes_juridicas AS fuente
                ON fuente.id_fuente = fragmento.id_fuente_juridica
            CROSS JOIN LATERAL (
                SELECT websearch_to_tsquery('spanish', ?) AS query
            ) AS consulta
            WHERE fragmento.id_fuente_juridica IS NOT NULL
                AND fragmento.id_archivo IS NULL
                AND fuente.validada = true
                AND fuente.estado = 'vigente'
                AND fuente.fecha_vigencia IS NOT NULL
                AND fuente.fecha_vigencia <= ?
                AND (fuente.fecha_fin_vigencia IS NULL OR fuente.fecha_fin_vigencia >= ?)
                AND fragmento.metadatos->>'md5_contenido_fuente' = md5(COALESCE(fuente.contenido_original, ''))
                AND to_tsvector('spanish', fragmento.contenido) @@ consulta.query
            ORDER BY relevancia DESC, fragmento.id_fragmento ASC
            LIMIT ?
            SQL, [trim($consulta), $hoy, $hoy, max(1, min($limite, 10))]);

        $resultados = array_map(static fn (object $fila): array => [
            'id' => (int) $fila->id_fragmento,
            'extracto' => $fila->contenido,
            'fuente' => [
                'titulo' => $fila->titulo,
                'tipo' => $fila->tipo_fuente,
                'numero_norma' => $fila->numero_norma,
                'version' => $fila->version,
                'fecha_vigencia' => $fila->fecha_vigencia,
                'fecha_fin_vigencia' => $fila->fecha_fin_vigencia,
            ],
            'relevancia' => (float) $fila->relevancia,
        ], $filas);

        return [
            'resultados' => $resultados,
            'cantidad_fuentes' => (int) $fuentes,
            'cantidad_fragmentos' => (int) $cantidadFragmentos,
        ];
    }
}
