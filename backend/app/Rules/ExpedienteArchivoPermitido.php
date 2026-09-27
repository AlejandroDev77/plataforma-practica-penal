<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

final class ExpedienteArchivoPermitido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('No se pudo leer uno de los archivos cargados.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if ($extension === 'docx') {
            if (! $this->isValidDocx($value)) {
                $fail('El DOCX no contiene una estructura Office Open XML válida.');
            }

            return;
        }

        $acceptedMimeTypes = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'tif' => ['image/tiff'],
            'tiff' => ['image/tiff'],
        ];
        $mimeType = $value->getMimeType();

        if (! isset($acceptedMimeTypes[$extension]) || ! in_array($mimeType, $acceptedMimeTypes[$extension], true)) {
            $fail('Cada archivo debe ser PDF, DOCX o una imagen JPG, PNG o TIFF.');

            return;
        }

        if ($extension === 'pdf') {
            $path = $value->getRealPath();
            if (! is_string($path)) {
                $fail('No se pudo verificar el archivo PDF.');

                return;
            }

            $handle = fopen($path, 'rb');
            $signature = $handle === false ? false : fread($handle, 5);
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($signature !== '%PDF-') {
                $fail('El archivo PDF no tiene una firma válida.');
            }
        }
    }

    private function isValidDocx(UploadedFile $file): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $path = $file->getRealPath();
        if (! is_string($path)) {
            return false;
        }

        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            return false;
        }

        try {
            $contentTypesStat = $archive->statName('[Content_Types].xml');
            $documentStat = $archive->statName('word/document.xml');

            if ($contentTypesStat === false || $contentTypesStat['size'] > 262_144
                || $documentStat === false || $documentStat['size'] < 1) {
                return false;
            }

            $contentTypes = $archive->getFromName('[Content_Types].xml');

            return is_string($contentTypes)
                && str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml');
        } finally {
            $archive->close();
        }
    }
}
