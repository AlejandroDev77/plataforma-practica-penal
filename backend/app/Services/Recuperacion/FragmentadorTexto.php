<?php

namespace App\Services\Recuperacion;

use InvalidArgumentException;

final class FragmentadorTexto
{
    /** @return list<string> */
    public function fragmentar(string $texto, int $maxCaracteres = 1600, int $solapamiento = 180): array
    {
        if ($maxCaracteres < 1 || $solapamiento < 0 || $solapamiento >= $maxCaracteres) {
            throw new InvalidArgumentException('Los límites del fragmentador no son válidos.');
        }

        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        $longitud = mb_strlen($texto, 'UTF-8');
        if ($longitud <= $maxCaracteres) {
            return [$texto];
        }

        $fragmentos = [];
        $inicio = 0;

        while ($inicio < $longitud) {
            $fin = min($inicio + $maxCaracteres, $longitud);

            if ($fin < $longitud) {
                $candidato = mb_substr($texto, $inicio, $fin - $inicio, 'UTF-8');
                $espacio = mb_strrpos($candidato, ' ', 0, 'UTF-8');
                if ($espacio !== false && $espacio >= (int) floor($maxCaracteres * 0.6)) {
                    $fin = $inicio + $espacio;
                }
            }

            $fragmento = trim(mb_substr($texto, $inicio, $fin - $inicio, 'UTF-8'));
            if ($fragmento !== '') {
                $fragmentos[] = $fragmento;
            }

            if ($fin >= $longitud) {
                break;
            }

            $inicio = max($inicio + 1, $fin - $solapamiento);
        }

        return $fragmentos;
    }
}
