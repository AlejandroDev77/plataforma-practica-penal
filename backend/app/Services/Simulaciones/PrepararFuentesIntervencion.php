<?php

namespace App\Services\Simulaciones;

use App\Models\FragmentoDocumento;
use App\Models\Simulacion;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class PrepararFuentesIntervencion
{
    /**
     * @param  list<int>  $idsFragmento
     * @return list<array{fragmento: FragmentoDocumento, extracto: string, metadatos: array<string, mixed>}>
     */
    public function ejecutar(Simulacion $simulacion, array $idsFragmento): array
    {
        if ($idsFragmento === []) {
            return [];
        }

        $fragmentos = FragmentoDocumento::query()
            ->whereIn('id_fragmento', $idsFragmento)
            ->with(['archivo', 'pagina', 'fuenteJuridica'])
            ->lockForUpdate()
            ->get()
            ->keyBy('id_fragmento');

        if ($fragmentos->count() !== count($idsFragmento)) {
            $this->fuenteInvalida();
        }

        $fuentes = [];
        foreach ($idsFragmento as $idFragmento) {
            /** @var FragmentoDocumento $fragmento */
            $fragmento = $fragmentos->get($idFragmento);
            $metadatos = $this->validarYCrearInstantanea($simulacion, $fragmento);
            $fuentes[] = [
                'fragmento' => $fragmento,
                'extracto' => $fragmento->contenido,
                'metadatos' => $metadatos,
            ];
        }

        return $fuentes;
    }

    /** @return array<string, mixed> */
    private function validarYCrearInstantanea(Simulacion $simulacion, FragmentoDocumento $fragmento): array
    {
        $extracto = trim($fragmento->contenido);
        if ($extracto === '') {
            $this->fuenteInvalida();
        }

        if ($fragmento->id_archivo !== null && $fragmento->id_fuente_juridica === null) {
            $archivo = $fragmento->archivo;
            $pagina = $fragmento->pagina;

            if ($archivo === null || (int) $archivo->id_expediente !== (int) $simulacion->id_expediente
                || $archivo->estado_procesamiento !== 'procesado') {
                $this->fuenteInvalida();
            }

            if ($fragmento->id_pagina !== null && ($pagina === null
                || ! $pagina->es_legible
                || (int) $pagina->id_archivo !== (int) $fragmento->id_archivo
                || ! $this->contieneExtracto((string) $pagina->texto_extraido, $extracto))) {
                $this->fuenteInvalida();
            }

            return [
                'tipo_fuente' => 'expediente',
                'titulo' => $archivo->nombre_original,
                'numero_pagina' => $pagina?->numero_pagina ?? $fragmento->numero_pagina,
                'localizador' => $pagina?->localizador,
            ];
        }

        if ($fragmento->id_archivo === null && $fragmento->id_fuente_juridica !== null) {
            $fuente = $fragmento->fuenteJuridica;
            $hoy = Carbon::today();

            if ($fuente === null
                || ! $fuente->validada
                || $fuente->estado !== 'vigente'
                || $fuente->fecha_vigencia === null
                || $fuente->fecha_vigencia->greaterThan($hoy)
                || ($fuente->fecha_fin_vigencia !== null && $fuente->fecha_fin_vigencia->lessThan($hoy))
                || ! $this->contieneExtracto((string) $fuente->contenido_original, $extracto)
                || ($fragmento->metadatos['md5_contenido_fuente'] ?? null) !== md5((string) $fuente->contenido_original)) {
                $this->fuenteInvalida();
            }

            return [
                'tipo_fuente' => 'juridica',
                'titulo' => $fuente->titulo,
                'categoria' => $fuente->tipo_fuente,
                'numero_norma' => $fuente->numero_norma,
                'version' => $fuente->version,
                'fecha_vigencia' => $fuente->fecha_vigencia->toDateString(),
                'fecha_fin_vigencia' => $fuente->fecha_fin_vigencia?->toDateString(),
                'hash_contenido_fuente' => hash('sha256', (string) $fuente->contenido_original),
            ];
        }

        $this->fuenteInvalida();
    }

    private function contieneExtracto(string $textoFuente, string $extracto): bool
    {
        $normalizar = static function (string $texto): string {
            $texto = preg_replace('/\s+/u', ' ', trim($texto));

            return mb_strtolower($texto ?? '', 'UTF-8');
        };

        $texto = $normalizar($textoFuente);
        $cita = $normalizar($extracto);

        return $texto !== '' && $cita !== '' && str_contains($texto, $cita);
    }

    private function fuenteInvalida(): never
    {
        throw ValidationException::withMessages([
            'source_fragment_ids' => 'Una o más fuentes no pertenecen al expediente o ya no son verificables.',
        ]);
    }
}
