<?php

namespace App\Jobs;

use App\Models\ArchivoExpediente;
use App\Models\HistorialProcesamiento;
use App\Services\Recuperacion\FragmentadorTexto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class IndexarArchivoExpediente implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $archivoId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(FragmentadorTexto $fragmentador): void
    {
        $historial = DB::transaction(function (): ?HistorialProcesamiento {
            $archivo = ArchivoExpediente::query()->whereKey($this->archivoId)->lockForUpdate()->first();
            if ($archivo === null || ! in_array($archivo->estado_procesamiento, ['procesado', 'error'], true)) {
                return null;
            }

            return HistorialProcesamiento::query()->create([
                'id_expediente' => $archivo->id_expediente,
                'id_archivo' => $archivo->getKey(),
                'tipo' => 'indexacion_rag',
                'estado' => 'procesando',
                'intento' => max(1, $this->attempts()),
                'fecha_inicio' => now(),
            ]);
        });

        if ($historial === null) {
            return;
        }

        try {
            $cantidad = DB::transaction(function () use ($fragmentador): int {
                $archivo = ArchivoExpediente::query()->whereKey($this->archivoId)->lockForUpdate()->first();
                if ($archivo === null) {
                    return 0;
                }

                if (! in_array($archivo->estado_procesamiento, ['procesado', 'error'], true)) {
                    throw new RuntimeException('El archivo cambió de estado durante la indexación.');
                }

                $fragmentos = [];
                $indice = 0;

                foreach ($archivo->paginas()->where('es_legible', true)->orderBy('numero_pagina')->get() as $pagina) {
                    foreach ($fragmentador->fragmentar((string) $pagina->texto_extraido) as $contenido) {
                        $fragmentos[] = [
                            'id_pagina' => $pagina->getKey(),
                            'numero_pagina' => $pagina->numero_pagina,
                            'indice_fragmento' => $indice++,
                            'contenido' => $contenido,
                        ];
                    }
                }

                $archivo->fragmentos()->delete();
                foreach ($fragmentos as $fragmento) {
                    $archivo->fragmentos()->create($fragmento);
                }

                return count($fragmentos);
            });

            $historial->update([
                'estado' => 'procesado',
                'metadatos' => [
                    'estrategia' => 'busqueda_textual_postgresql_espanol',
                    'cantidad_fragmentos' => $cantidad,
                ],
                'fecha_fin' => now(),
            ]);
        } catch (Throwable $exception) {
            $historial->update([
                'estado' => 'error',
                'mensaje_error' => 'No se pudo indexar el texto extraído.',
                'fecha_fin' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        HistorialProcesamiento::query()
            ->where('id_archivo', $this->archivoId)
            ->where('tipo', 'indexacion_rag')
            ->where('estado', 'procesando')
            ->latest('id_proceso')
            ->first()
            ?->update([
                'estado' => 'error',
                'mensaje_error' => 'No se pudo completar la indexación del texto.',
                'fecha_fin' => now(),
            ]);
    }
}
