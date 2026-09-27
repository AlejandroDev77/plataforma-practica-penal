<?php

namespace App\Jobs;

use App\Models\ArchivoExpediente;
use App\Models\Expediente;
use App\Models\HistorialProcesamiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class ProcesarArchivoExpediente implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $archivoId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(): void
    {
        $historyId = DB::transaction(function (): ?int {
            $archivo = ArchivoExpediente::query()->whereKey($this->archivoId)->lockForUpdate()->first();
            if ($archivo === null || $archivo->estado_procesamiento === 'procesado') {
                return null;
            }

            $archivo->update([
                'estado_procesamiento' => 'procesando',
                'mensaje_error' => null,
            ]);

            $history = HistorialProcesamiento::query()->create([
                'id_expediente' => $archivo->id_expediente,
                'id_archivo' => $archivo->getKey(),
                'tipo' => 'extraccion_texto',
                'estado' => 'procesando',
                'intento' => max(1, $this->attempts()),
                'fecha_inicio' => now(),
            ]);

            $this->syncExpedienteStatus($archivo->id_expediente);

            return $history->getKey();
        });

        if ($historyId === null) {
            return;
        }

        $history = HistorialProcesamiento::query()->findOrFail($historyId);
        $archivo = ArchivoExpediente::query()->findOrFail($this->archivoId);

        try {
            $response = $this->requestExtraction($archivo);
            if ($response->clientError()) {
                $this->markRejected($archivo, $history);

                return;
            }

            $response->throw();
            $body = $response->json();
            if (! is_array($body)) {
                throw new UnexpectedValueException('El servicio documental devolvió una respuesta inválida.');
            }

            $result = Validator::make($body, [
                'document_type' => ['required', 'in:pdf,docx,image'],
                'page_count' => ['nullable', 'integer', 'min:1'],
                'pages' => ['required', 'array', 'min:1', 'max:500'],
                'pages.*.page_number' => ['required', 'integer', 'min:1'],
                'pages.*.locator' => ['required', 'string', 'max:200'],
                'pages.*.text' => ['required', 'string'],
                'pages.*.used_ocr' => ['required', 'boolean'],
                'pages.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
                'pages.*.is_readable' => ['required', 'boolean'],
                'warnings' => ['present', 'array', 'max:500'],
                'warnings.*.code' => ['required', 'in:ocr_unavailable,ocr_failed,text_not_recognized,pagination_unavailable'],
                'warnings.*.page_number' => ['nullable', 'integer', 'min:1'],
                'warnings.*.message' => ['required', 'string', 'max:300'],
            ])->validate();

            $this->persistExtraction($archivo, $history, $result);
        } catch (Throwable $exception) {
            $history->update([
                'estado' => 'error',
                'mensaje_error' => 'No se pudo completar esta tentativa de extracción.',
                'fecha_fin' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function (): void {
            $archivo = ArchivoExpediente::query()->whereKey($this->archivoId)->lockForUpdate()->first();
            if ($archivo === null || $archivo->estado_procesamiento === 'procesado') {
                return;
            }

            $archivo->update([
                'estado_procesamiento' => 'error',
                'mensaje_error' => 'No se pudo extraer el texto. Revise el archivo e inténtelo de nuevo.',
            ]);

            $archivo->procesamientos()
                ->where('tipo', 'extraccion_texto')
                ->where('intento', max(1, $this->attempts()))
                ->latest('id_proceso')
                ->first()
                ?->update([
                    'estado' => 'error',
                    'mensaje_error' => 'No se pudo completar la extracción.',
                    'fecha_fin' => now(),
                ]);

            $this->syncExpedienteStatus($archivo->id_expediente);
        });
    }

    private function requestExtraction(ArchivoExpediente $archivo): Response
    {
        $stream = Storage::disk($archivo->disco)->readStream($archivo->ruta_almacenamiento);
        if (! is_resource($stream)) {
            throw new RuntimeException('No se pudo leer el archivo privado para extraer su texto.');
        }

        try {
            return Http::connectTimeout(5)
                ->timeout((int) config('services.intelligence.timeout', 300))
                ->withToken((string) config('services.intelligence.token', ''))
                ->attach('file', $stream, $archivo->nombre_original, [
                    'Content-Type' => $archivo->tipo_mime,
                ])
                ->post(rtrim((string) config('services.intelligence.url'), '/').'/api/v1/documents/extract');
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $result */
    private function persistExtraction(ArchivoExpediente $archivo, HistorialProcesamiento $history, array $result): void
    {
        $warnings = collect($result['warnings']);
        $warningCodes = $warnings->pluck('code')->filter(fn (string $code): bool => $code !== 'pagination_unavailable')->unique()->values();
        $unreadablePages = collect($result['pages'])->contains(fn (array $page): bool => ! $page['is_readable']);
        $hasIssues = $warningCodes->isNotEmpty() || $unreadablePages;
        $message = $hasIssues
            ? 'La extracción fue parcial. Revise las páginas marcadas como no legibles.'
            : null;
        $usedOcr = collect($result['pages'])->contains(fn (array $page): bool => $page['used_ocr']);
        $requiresOcr = $usedOcr || $warningCodes->contains(fn (string $code): bool => in_array($code, ['ocr_unavailable', 'ocr_failed'], true));

        DB::transaction(function () use ($archivo, $history, $result, $warningCodes, $hasIssues, $message, $requiresOcr, $usedOcr): void {
            $locked = ArchivoExpediente::query()->whereKey($archivo->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $locked->paginas()->delete();
            foreach ($result['pages'] as $page) {
                $locked->paginas()->create([
                    'numero_pagina' => $page['page_number'],
                    'localizador' => $page['locator'],
                    'texto_extraido' => $page['text'],
                    'uso_ocr' => $page['used_ocr'],
                    'nivel_confianza' => $page['confidence'],
                    'es_legible' => $page['is_readable'],
                ]);
            }

            $locked->update([
                'cantidad_paginas' => $result['page_count'],
                'requiere_ocr' => $requiresOcr,
                'estado_procesamiento' => $hasIssues ? 'error' : 'procesado',
                'mensaje_error' => $message,
            ]);

            $history->update([
                'estado' => $hasIssues ? 'error' : 'procesado',
                'mensaje_error' => $message,
                'metadatos' => [
                    'document_type' => $result['document_type'],
                    'page_count' => $result['page_count'],
                    'used_ocr' => $usedOcr,
                    'warning_codes' => $warningCodes->all(),
                ],
                'fecha_fin' => now(),
            ]);

            $this->syncExpedienteStatus($locked->id_expediente);
        });
    }

    private function markRejected(ArchivoExpediente $archivo, HistorialProcesamiento $history): void
    {
        DB::transaction(function () use ($archivo, $history): void {
            $locked = ArchivoExpediente::query()->whereKey($archivo->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $message = 'El servicio documental rechazó el formato o la estructura de este archivo.';
            $locked->update(['estado_procesamiento' => 'error', 'mensaje_error' => $message]);
            $history->update([
                'estado' => 'error',
                'mensaje_error' => $message,
                'fecha_fin' => now(),
            ]);
            $this->syncExpedienteStatus($locked->id_expediente);
        });
    }

    private function syncExpedienteStatus(int $expedienteId): void
    {
        $expediente = Expediente::query()->whereKey($expedienteId)->lockForUpdate()->first();
        if ($expediente === null) {
            return;
        }

        $states = $expediente->archivos()->pluck('estado_procesamiento');
        $status = match (true) {
            $states->isEmpty() => 'pendiente',
            $states->contains('procesando') => 'procesando',
            $states->contains('pendiente') => 'pendiente',
            $states->contains('error') => 'error',
            default => 'procesado',
        };

        $expediente->forceFill(['estado_procesamiento' => $status])->save();
    }
}
