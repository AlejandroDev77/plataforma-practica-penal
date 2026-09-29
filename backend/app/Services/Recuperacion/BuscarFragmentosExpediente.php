<?php

namespace App\Services\Recuperacion;

use App\Models\Expediente;
use Illuminate\Support\Facades\DB;

final class BuscarFragmentosExpediente
{
    /**
     * @return array{resultados: list<array<string, mixed>>, cantidad_fragmentos: int, estado_indice: string}
     */
    public function ejecutar(Expediente $expediente, string $consulta, int $limite = 5): array
    {
        $cantidadFragmentos = DB::table('fragmentos_documento as fragmento')
            ->join('archivos_expediente as archivo', 'archivo.id_archivo', '=', 'fragmento.id_archivo')
            ->where('archivo.id_expediente', $expediente->getKey())
            ->whereNotNull('fragmento.id_archivo')
            ->count();

        $estadoIndice = $expediente->procesamientos()
            ->where('tipo', 'indexacion_rag')
            ->orderByDesc('id_proceso')
            ->value('estado') ?? 'sin_indice';

        $filas = DB::select(<<<'SQL'
            SELECT
                fragmento.id_fragmento,
                fragmento.contenido,
                archivo.nombre_original,
                fragmento.numero_pagina,
                pagina.localizador,
                ts_rank_cd(
                    to_tsvector('spanish', fragmento.contenido),
                    consulta.query,
                    32
                ) AS relevancia
            FROM fragmentos_documento AS fragmento
            INNER JOIN archivos_expediente AS archivo
                ON archivo.id_archivo = fragmento.id_archivo
            LEFT JOIN paginas_expediente AS pagina
                ON pagina.id_pagina = fragmento.id_pagina
                AND pagina.id_archivo = fragmento.id_archivo
            CROSS JOIN LATERAL (
                SELECT websearch_to_tsquery('spanish', ?) AS query
            ) AS consulta
            WHERE archivo.id_expediente = ?
                AND fragmento.id_archivo IS NOT NULL
                AND fragmento.id_fuente_juridica IS NULL
                AND to_tsvector('spanish', fragmento.contenido) @@ consulta.query
            ORDER BY relevancia DESC, fragmento.id_fragmento ASC
            LIMIT ?
            SQL, [trim($consulta), $expediente->getKey(), max(1, min($limite, 10))]);

        $resultados = array_map(static fn (object $fila): array => [
            'id' => (int) $fila->id_fragmento,
            'extracto' => $fila->contenido,
            'fuente' => [
                'archivo' => $fila->nombre_original,
                'pagina' => $fila->numero_pagina === null ? null : (int) $fila->numero_pagina,
                'localizador' => $fila->localizador,
            ],
            'relevancia' => (float) $fila->relevancia,
        ], $filas);

        return [
            'resultados' => $resultados,
            'cantidad_fragmentos' => (int) $cantidadFragmentos,
            'estado_indice' => (string) $estadoIndice,
        ];
    }
}
