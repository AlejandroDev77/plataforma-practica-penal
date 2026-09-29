<?php

namespace App\Services\Analisis;

use App\Models\AnalisisExpediente;
use App\Models\CronologiaExpediente;
use App\Models\DelitoExpediente;
use App\Models\Expediente;
use App\Models\HechoExpediente;
use App\Models\HistorialProcesamiento;
use App\Models\IncidenciaAnalisis;
use App\Models\PaginaExpediente;
use App\Models\ParticipanteExpediente;
use App\Models\PruebaExpediente;
use App\Models\ReferenciaExpediente;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistirAnalisisExpediente
{
    public function ejecutar(
        Expediente $expediente,
        array $resultado,
        ?int $procesoId = null,
        ?string $proveedorIa = null,
        ?string $modeloIa = null,
    ): AnalisisExpediente {
        return DB::transaction(function () use ($expediente, $resultado, $procesoId, $proveedorIa, $modeloIa): AnalisisExpediente {
            $expediente = Expediente::query()->whereKey($expediente->getKey())->lockForUpdate()->firstOrFail();
            $paginas = $this->validarCitas($expediente, $resultado);
            $version = ((int) $expediente->analisis()->max('version')) + 1;

            $analisis = $expediente->analisis()->create([
                'resumen' => $resultado['summary']['text'] ?? null,
                'etapa_procesal' => $resultado['procedural_stage']['text'] ?? null,
                'estado' => 'procesado',
                'version' => $version,
                'datos_estructurados' => $resultado,
                'modelo_ia' => $modeloIa,
                'fecha_analisis' => now(),
            ]);

            $this->persistirParticipantes($expediente, $analisis, $resultado['participants'], $paginas);
            $this->persistirDelitos($expediente, $analisis, $resultado['offenses'], $paginas);
            $this->persistirHechos($expediente, $analisis, $resultado['facts'], $paginas);
            $this->persistirPruebas($expediente, $analisis, $resultado['evidence'], $paginas);
            $this->persistirCronologia($expediente, $analisis, $resultado['chronology'], $paginas);
            $this->persistirIncidencias($expediente, $analisis, $resultado, $paginas);

            $metadatos = [
                'schema_version' => $resultado['schema_version'],
                'version_analisis' => $version,
            ];

            if ($procesoId === null) {
                HistorialProcesamiento::query()->create([
                    'id_expediente' => $expediente->getKey(),
                    'id_analisis' => $analisis->getKey(),
                    'tipo' => 'analisis_estructurado',
                    'estado' => 'procesado',
                    'intento' => 1,
                    'metadatos' => $metadatos,
                    'fecha_inicio' => now(),
                    'fecha_fin' => now(),
                ]);
            } else {
                $proceso = HistorialProcesamiento::query()
                    ->whereKey($procesoId)
                    ->where('id_expediente', $expediente->getKey())
                    ->where('tipo', 'analisis_estructurado')
                    ->lockForUpdate()
                    ->firstOrFail();
                $proceso->update([
                    'id_analisis' => $analisis->getKey(),
                    'estado' => 'procesado',
                    'mensaje_error' => null,
                    'metadatos' => [
                        ...($proceso->metadatos ?? []),
                        ...$metadatos,
                        ...($proveedorIa === null ? [] : ['proveedor_ia' => $proveedorIa]),
                        ...($modeloIa === null ? [] : ['modelo_ia' => $modeloIa]),
                    ],
                    'fecha_fin' => now(),
                ]);
            }

            return $analisis;
        }, 3);
    }

    /** @return array<int, PaginaExpediente> */
    private function validarCitas(Expediente $expediente, array $resultado): array
    {
        $citas = CitasAnalisis::recopilar($resultado);
        $idsPagina = collect($citas)->pluck('page_id')->map(fn ($id): int => (int) $id)->unique()->values();

        if ($idsPagina->count() > 10) {
            throw ValidationException::withMessages([
                'result' => 'El resultado cita más de diez páginas; divida el análisis en lotes pequeños.',
            ]);
        }

        $paginas = PaginaExpediente::query()
            ->with('archivo')
            ->whereIn('id_pagina', $idsPagina)
            ->whereHas('archivo', fn ($query) => $query->where('id_expediente', $expediente->getKey()))
            ->get()
            ->keyBy('id_pagina');

        if ($paginas->count() !== $idsPagina->count()) {
            throw ValidationException::withMessages([
                'result' => 'Una o más citas apuntan a páginas que no pertenecen a este expediente.',
            ]);
        }

        $caracteres = 0;
        foreach ($paginas as $pagina) {
            if (! $pagina->es_legible || ! is_string($pagina->texto_extraido) || trim($pagina->texto_extraido) === '') {
                throw ValidationException::withMessages([
                    'result' => 'No se puede citar una página sin texto legible.',
                ]);
            }

            $caracteres += mb_strlen($pagina->texto_extraido, 'UTF-8');
        }

        if ($caracteres > 40_000) {
            throw ValidationException::withMessages([
                'result' => 'Las páginas citadas superan el límite de texto permitido para un lote.',
            ]);
        }

        foreach ($citas as $cita) {
            $pagina = $paginas->get((int) $cita['page_id']);

            if ($pagina === null || ! CitasAnalisis::coincide($pagina->texto_extraido, $cita['excerpt'])) {
                throw ValidationException::withMessages([
                    'result' => 'Una cita no coincide literalmente con el texto de la página señalada.',
                ]);
            }
        }

        return $paginas->all();
    }

    private function persistirParticipantes(Expediente $expediente, AnalisisExpediente $analisis, array $hallazgos, array $paginas): void
    {
        foreach ($hallazgos as $hallazgo) {
            $registro = ParticipanteExpediente::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'nombre' => $hallazgo['name_as_written'],
                'tipo_participante' => $hallazgo['role_as_written'] ?? 'no_especificado',
                'descripcion' => $hallazgo['description'] ?? null,
                'confirmado' => false,
                'nivel_confianza' => null,
                'datos_adicionales' => ['certeza' => $hallazgo['certainty']],
            ]);
            $this->persistirReferencias($expediente, $analisis, $hallazgo['sources'], 'id_participante', $registro->getKey(), $paginas);
        }
    }

    private function persistirDelitos(Expediente $expediente, AnalisisExpediente $analisis, array $hallazgos, array $paginas): void
    {
        foreach ($hallazgos as $hallazgo) {
            $registro = DelitoExpediente::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'nombre_delito' => $hallazgo['label_as_written'],
                'articulo_referido' => $hallazgo['article_as_written'] ?? null,
                'descripcion' => $hallazgo['description'] ?? null,
                'confirmado' => false,
                'nivel_confianza' => null,
            ]);
            $this->persistirReferencias($expediente, $analisis, $hallazgo['sources'], 'id_delito', $registro->getKey(), $paginas);
        }
    }

    private function persistirHechos(Expediente $expediente, AnalisisExpediente $analisis, array $hallazgos, array $paginas): void
    {
        foreach ($hallazgos as $indice => $hallazgo) {
            $registro = HechoExpediente::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'descripcion' => $hallazgo['description'],
                'tipo_hecho' => $hallazgo['kind'],
                'fecha_hecho' => $this->fechaInequivoca($hallazgo['date_as_written'] ?? null),
                'orden_cronologico' => $indice,
                'confirmado' => false,
                'nivel_confianza' => null,
            ]);
            $this->persistirReferencias($expediente, $analisis, $hallazgo['sources'], 'id_hecho', $registro->getKey(), $paginas);
        }
    }

    private function persistirPruebas(Expediente $expediente, AnalisisExpediente $analisis, array $hallazgos, array $paginas): void
    {
        foreach ($hallazgos as $hallazgo) {
            $registro = PruebaExpediente::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'nombre' => $hallazgo['name_as_written'],
                'tipo_prueba' => $hallazgo['kind_as_written'] ?? 'no_especificado',
                'descripcion' => $hallazgo['description'] ?? null,
                'estado' => $hallazgo['status_as_written'] ?? null,
                'confirmado' => false,
                'nivel_confianza' => null,
            ]);
            $this->persistirReferencias($expediente, $analisis, $hallazgo['sources'], 'id_prueba', $registro->getKey(), $paginas);
        }
    }

    private function persistirCronologia(Expediente $expediente, AnalisisExpediente $analisis, array $hallazgos, array $paginas): void
    {
        foreach ($hallazgos as $indice => $hallazgo) {
            $registro = CronologiaExpediente::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'fecha_evento' => $this->fechaInequivoca($hallazgo['date_as_written'] ?? null),
                'titulo' => $hallazgo['title'],
                'descripcion' => $hallazgo['description'],
                'orden' => $indice,
                'nivel_confianza' => null,
            ]);
            $this->persistirReferencias($expediente, $analisis, $hallazgo['sources'], 'id_evento', $registro->getKey(), $paginas);
        }
    }

    private function persistirIncidencias(Expediente $expediente, AnalisisExpediente $analisis, array $resultado, array $paginas): void
    {
        foreach ($resultado['missing_information'] as $vacante) {
            $registro = IncidenciaAnalisis::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'tipo' => 'informacion_faltante',
                'descripcion' => $vacante['question'],
                'gravedad' => 'baja',
                'resuelta' => false,
                'datos_adicionales' => ['relevancia' => $vacante['relevance']],
            ]);
            $this->persistirReferencias($expediente, $analisis, $vacante['context_sources'], 'id_incidencia', $registro->getKey(), $paginas);
        }

        foreach ($resultado['uncertainties'] as $incertidumbre) {
            $registro = IncidenciaAnalisis::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'tipo' => 'incertidumbre',
                'descripcion' => $incertidumbre['issue'],
                'gravedad' => 'media',
                'resuelta' => false,
                'datos_adicionales' => ['explicacion' => $incertidumbre['explanation'], 'certeza' => 'incierto'],
            ]);
            $this->persistirReferencias($expediente, $analisis, $incertidumbre['sources'], 'id_incidencia', $registro->getKey(), $paginas);
        }
    }

    private function persistirReferencias(
        Expediente $expediente,
        AnalisisExpediente $analisis,
        array $citas,
        string $columnaElemento,
        int $idElemento,
        array $paginas,
    ): void {
        foreach ($citas as $cita) {
            $pagina = $paginas[(int) $cita['page_id']];

            ReferenciaExpediente::query()->create([
                'id_expediente' => $expediente->getKey(),
                'id_analisis' => $analisis->getKey(),
                'id_archivo' => $pagina->id_archivo,
                'id_pagina' => $pagina->getKey(),
                $columnaElemento => $idElemento,
                'texto_fuente' => $cita['excerpt'],
                'nivel_confianza' => null,
            ]);
        }
    }

    private function fechaInequivoca(?string $fecha): ?string
    {
        if ($fecha === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            return null;
        }

        $parseada = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        $errores = DateTimeImmutable::getLastErrors();

        if ($parseada === false
            || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))
            || $parseada->format('Y-m-d') !== $fecha) {
            return null;
        }

        return $fecha;
    }
}
