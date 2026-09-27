<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\ExpedienteArchivoPermitido;
use Illuminate\Foundation\Http\FormRequest;

final class StoreExpedienteArchivosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'archivos' => ['required', 'array', 'min:1', 'max:5'],
            'archivos.*' => ['required', 'file', new ExpedienteArchivoPermitido, 'max:51200'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivos.required' => 'Selecciona al menos un archivo.',
            'archivos.max' => 'Puedes cargar hasta 5 archivos a la vez.',
            'archivos.*.mimes' => 'Cada archivo debe ser PDF, DOCX o una imagen JPG, PNG o TIFF.',
            'archivos.*.max' => 'Cada archivo puede ocupar como máximo 50 MB.',
        ];
    }
}
