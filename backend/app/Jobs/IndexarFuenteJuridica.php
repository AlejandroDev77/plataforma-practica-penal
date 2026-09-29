<?php

namespace App\Jobs;

use App\Models\FuenteJuridica;
use App\Services\Recuperacion\FragmentadorTexto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class IndexarFuenteJuridica implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $fuenteId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(FragmentadorTexto $fragmentador): void
    {
        DB::transaction(function () use ($fragmentador): void {
            $fuente = FuenteJuridica::query()->whereKey($this->fuenteId)->lockForUpdate()->first();
            if ($fuente === null) {
                return;
            }

            $fechaVigencia = $fuente->fecha_vigencia?->toDateString();
            $fechaFinVigencia = $fuente->fecha_fin_vigencia?->toDateString();
            $hoy = today()->toDateString();
            $vigente = $fuente->validada
                && $fuente->estado === 'vigente'
                && $fechaVigencia !== null
                && $fechaVigencia <= $hoy
                && ($fechaFinVigencia === null || $fechaFinVigencia >= $hoy);

            if (! $vigente) {
                $fuente->fragmentos()->delete();

                return;
            }

            $contenidoOriginal = (string) $fuente->contenido_original;
            $contenido = trim($contenidoOriginal);
            $fragmentos = $fragmentador->fragmentar($contenido);
            $hashContenido = md5($contenidoOriginal);
            $fuente->fragmentos()->delete();

            foreach ($fragmentos as $indice => $fragmento) {
                $fuente->fragmentos()->create([
                    'contenido' => $fragmento,
                    'indice_fragmento' => $indice,
                    'metadatos' => [
                        'md5_contenido_fuente' => $hashContenido,
                        'estrategia' => 'fragmentacion_textual_v1',
                    ],
                ]);
            }
        });
    }
}
