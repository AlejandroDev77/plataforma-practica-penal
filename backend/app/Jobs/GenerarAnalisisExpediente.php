<?php

namespace App\Jobs;

use App\Http\Requests\Api\V1\GuardarAnalisisEstructuradoRequest;
use App\Models\Expediente;
use App\Models\HistorialProcesamiento;
use App\Models\PaginaExpediente;
use App\Services\Analisis\PersistirAnalisisExpediente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class GenerarAnalisisExpediente implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $procesoId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(PersistirAnalisisExpediente $persistir): void
    {
        $proceso = DB::transaction(function (): ?HistorialProcesamiento {
            $registro = HistorialProcesamiento::query()->whereKey($this->procesoId)->lockForUpdate()->first();
            if ($registro === null || $registro->estado === 'procesado') {
                return null;
            }

            $registro->update([
                'estado' => 'procesando',
                'intento' => max(1, $this->attempts()),
                'mensaje_error' => null,
                'fecha_inicio' => $registro->fecha_inicio ?? now(),
                'fecha_fin' => null,
            ]);

            return $registro->fresh();
        });

        if ($proceso === null) {
            return;
        }

        try {
            $expediente = Expediente::query()->findOrFail($proceso->id_expediente);
            $pageIds = $proceso->metadatos['page_ids'] ?? null;
            if (! is_array($pageIds) || $pageIds === [] || count($pageIds) > 10) {
                throw new UnexpectedValueException('La solicitud de análisis no contiene páginas válidas.');
            }

            $paginas = PaginaExpediente::query()
                ->with('archivo')
                ->whereIn('id_pagina', $pageIds)
                ->whereHas('archivo', fn ($query) => $query->where('id_expediente', $expediente->getKey()))
                ->orderBy('id_archivo')
                ->orderBy('numero_pagina')
                ->get();

            if ($paginas->count() !== count($pageIds)) {
                throw new UnexpectedValueException('Una o más páginas ya no pertenecen al expediente.');
            }

            $caracteres = 0;
            foreach ($paginas as $pagina) {
                if (! $pagina->es_legible || ! is_string($pagina->texto_extraido) || trim($pagina->texto_extraido) === '') {
                    throw new UnexpectedValueException('Una o más páginas ya no contienen texto legible.');
                }

                $caracteres += mb_strlen($pagina->texto_extraido, 'UTF-8');
            }

            if ($caracteres > 40_000) {
                throw new UnexpectedValueException('Las páginas exceden el límite de texto permitido.');
            }

            $response = $this->requestAnalysis($paginas->map(fn (PaginaExpediente $pagina): array => [
                'page_id' => $pagina->getKey(),
                'file_id' => $pagina->id_archivo,
                'page_number' => $pagina->numero_pagina,
                'locator' => $pagina->localizador,
                'text' => $pagina->texto_extraido,
            ])->all());
            $response->throw();

            $proveedor = trim((string) $response->header('X-Jurissim-Analysis-Provider', ''));
            $modelo = trim((string) $response->header('X-Jurissim-Analysis-Model', ''));
            if ($proveedor !== 'ollama' || $modelo === '' || mb_strlen($modelo, 'UTF-8') > 150) {
                throw new UnexpectedValueException('El servicio local no confirmó el proveedor y modelo utilizados.');
            }

            $resultado = $response->json();
            if (! is_array($resultado)) {
                throw new UnexpectedValueException('El servicio local devolvió una respuesta inválida.');
            }

            $reglas = new GuardarAnalisisEstructuradoRequest;
            $reglas->merge(['result' => $resultado]);
            $validated = Validator::make(['result' => $resultado], $reglas->rules())->validate();

            $persistir->ejecutar($expediente, $validated['result'], $proceso->getKey(), $proveedor, $modelo);
        } catch (Throwable $exception) {
            HistorialProcesamiento::query()->whereKey($this->procesoId)->where('estado', 'procesando')->update([
                'mensaje_error' => 'No se pudo completar esta tentativa de análisis local.',
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        HistorialProcesamiento::query()->whereKey($this->procesoId)->where('estado', '!=', 'procesado')->update([
            'estado' => 'error',
            'mensaje_error' => 'No se pudo completar el análisis local. Revise las páginas y vuelva a intentarlo.',
            'fecha_fin' => now(),
        ]);
    }

    /** @param list<array{page_id: int, file_id: int, page_number: int, locator: string, text: string}> $paginas */
    private function requestAnalysis(array $paginas): Response
    {
        $url = (string) config('services.intelligence.url', '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT);
        if ($scheme !== 'http' || $port !== 8100 || ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('El análisis de expedientes solo puede enviarse al servicio local.');
        }

        $token = trim((string) config('services.intelligence.token', ''));
        if ($token === '') {
            throw new RuntimeException('Falta configurar el token local del servicio de análisis.');
        }

        return Http::connectTimeout(5)
            ->timeout(max(1, (int) config('services.intelligence.timeout', 300)))
            ->withoutRedirecting()
            ->withToken($token)
            ->post(rtrim($url, '/').'/api/v1/analysis/analyze', ['pages' => $paginas]);
    }
}
