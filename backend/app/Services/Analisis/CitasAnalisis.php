<?php

namespace App\Services\Analisis;

final class CitasAnalisis
{
    /** @return list<array{page_id: int, excerpt: string}> */
    public static function recopilar(array $resultado): array
    {
        $citas = [];

        foreach (['summary', 'procedural_stage'] as $clave) {
            if (is_array($resultado[$clave] ?? null)) {
                array_push($citas, ...($resultado[$clave]['sources'] ?? []));
            }
        }

        foreach (['participants', 'offenses', 'facts', 'evidence', 'chronology', 'uncertainties'] as $coleccion) {
            foreach ($resultado[$coleccion] ?? [] as $hallazgo) {
                array_push($citas, ...($hallazgo['sources'] ?? []));
            }
        }

        foreach ($resultado['missing_information'] ?? [] as $vacante) {
            array_push($citas, ...($vacante['context_sources'] ?? []));
        }

        return $citas;
    }

    public static function coincide(string $textoPagina, string $extracto): bool
    {
        return str_contains(self::normalizar($textoPagina), self::normalizar($extracto));
    }

    private static function normalizar(string $texto): string
    {
        $espaciosNormalizados = preg_replace('/\s+/u', ' ', trim($texto));

        return mb_strtolower($espaciosNormalizados ?? trim($texto), 'UTF-8');
    }
}
