<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateExpedienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['titulo', 'descripcion', 'numero_caso'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'numero_caso' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
