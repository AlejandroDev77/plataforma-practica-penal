<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class BuscarRecuperacionExpedienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'consulta' => ['required', 'string', 'min:3', 'max:500'],
            'limite' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'consulta.required' => 'Escriba qué información desea localizar.',
            'consulta.min' => 'La consulta debe tener al menos :min caracteres.',
            'consulta.max' => 'La consulta no puede superar :max caracteres.',
            'limite.integer' => 'El límite de resultados debe ser un número entero.',
            'limite.min' => 'Debe solicitar al menos un resultado.',
            'limite.max' => 'Puede solicitar como máximo :max resultados.',
        ];
    }
}
