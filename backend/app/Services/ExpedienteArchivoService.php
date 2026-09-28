<?php

namespace App\Services;

use App\Models\AnalisisExpediente;
use App\Models\ArchivoExpediente;
use App\Models\Expediente;
use App\Models\ObjetoPendienteEliminacion;
use App\Services\Analisis\CitasAnalisis;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ExpedienteArchivoService
{
    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<ArchivoExpediente>
     */
    public function store(Expediente $expediente, array $uploads): array
    {
        $disk = (string) config('filesystems.default', 'local');
        $storedPaths = [];
        $metadata = [];

        try {
            foreach ($uploads as $upload) {
                $extension = strtolower($upload->getClientOriginalExtension());
                if ($extension === '') {
                    throw new RuntimeException('No se pudo identificar el formato de uno de los archivos.');
                }

                $storedName = Str::uuid().'.'.$extension;
                $directory = 'expedientes/'.$expediente->getKey();
                $path = Storage::disk($disk)->putFileAs($directory, $upload, $storedName);

                if (! is_string($path)) {
                    throw new RuntimeException('No se pudo guardar uno de los archivos en el almacenamiento privado.');
                }

                $storedPaths[] = [$disk, $path];
                $originalName = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
                $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '_', $originalName) ?: 'archivo.'.$extension;
                $metadata[] = [
                    'nombre_original' => Str::limit($originalName, 255, ''),
                    'nombre_almacenado' => $storedName,
                    'tipo_mime' => $extension === 'docx'
                        ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        : ($upload->getMimeType() ?: 'application/octet-stream'),
                    'extension' => $extension,
                    'disco' => $disk,
                    'ruta_almacenamiento' => $path,
                    'tamano_bytes' => $upload->getSize(),
                    'cantidad_paginas' => null,
                    'requiere_ocr' => str_starts_with((string) $upload->getMimeType(), 'image/'),
                    'estado_procesamiento' => 'pendiente',
                ];
            }

            return DB::transaction(function () use ($expediente, $metadata): array {
                $stored = [];
                foreach ($metadata as $attributes) {
                    $stored[] = $expediente->archivos()->create($attributes);
                }

                return $stored;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as [$storedDisk, $path]) {
                try {
                    $deleted = Storage::disk($storedDisk)->delete($path);
                    if (! $deleted && Storage::disk($storedDisk)->exists($path)) {
                        throw new RuntimeException('El objeto cargado no se pudo retirar durante la compensación.');
                    }
                } catch (Throwable $cleanupException) {
                    try {
                        ObjetoPendienteEliminacion::query()->firstOrCreate(
                            ['disco' => $storedDisk, 'ruta_almacenamiento' => $path],
                            [
                                'estado' => 'pendiente',
                                'intentos' => 1,
                                'ultimo_error' => Str::limit($cleanupException->getMessage(), 2000),
                            ],
                        );
                    } catch (Throwable $queueException) {
                        report($queueException);
                    }

                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    public function remove(Expediente $expediente, int $archivoId): void
    {
        $object = DB::transaction(function () use ($expediente, $archivoId): ?array {
            $caso = Expediente::query()->whereKey($expediente->getKey())->lockForUpdate()->firstOrFail();
            $archivo = $caso->archivos()->whereKey($archivoId)->lockForUpdate()->firstOrFail();
            $object = ['disco' => $archivo->disco, 'ruta' => $archivo->ruta_almacenamiento];

            $paginasEliminadas = $archivo->paginas()->pluck('id_pagina')->map(fn ($id): int => (int) $id)->all();
            if ($paginasEliminadas !== []) {
                AnalisisExpediente::query()
                    ->where('id_expediente', $caso->getKey())
                    ->get(['id_analisis', 'id_expediente', 'datos_estructurados'])
                    ->each(function (AnalisisExpediente $analisis) use ($paginasEliminadas): void {
                        $citaPaginas = collect(CitasAnalisis::recopilar($analisis->datos_estructurados ?? []))
                            ->pluck('page_id')
                            ->map(fn ($id): int => (int) $id);

                        if ($citaPaginas->intersect($paginasEliminadas)->isNotEmpty()) {
                            // El resultado derivado y su historial también contienen información del archivo retirado.
                            $analisis->delete();
                        }
                    });
            }

            $archivo->delete();

            return $object;
        });

        if ($object !== null) {
            $this->cleanPendingObject($object['disco'], $object['ruta']);
        }
    }

    /**
     * @param  list<array{disco: string, ruta: string}>  $objects
     */
    public function cleanPendingObjects(array $objects): void
    {
        foreach ($objects as $object) {
            $this->cleanPendingObject($object['disco'], $object['ruta']);
        }
    }

    private function cleanPendingObject(string $disk, string $path): void
    {
        $pending = ObjetoPendienteEliminacion::query()
            ->where('disco', $disk)
            ->where('ruta_almacenamiento', $path)
            ->where('estado', 'pendiente')
            ->first();

        if ($pending === null) {
            return;
        }

        try {
            $deleted = Storage::disk($disk)->delete($path);
            if (! $deleted && Storage::disk($disk)->exists($path)) {
                throw new RuntimeException('El almacenamiento privado no confirmó la eliminación del objeto.');
            }

            $pending->update(['estado' => 'procesado', 'ultimo_error' => null]);
        } catch (Throwable $exception) {
            $pending->update([
                'intentos' => $pending->intentos + 1,
                'ultimo_error' => Str::limit($exception->getMessage(), 2000),
            ]);
            report($exception);
        }
    }
}
