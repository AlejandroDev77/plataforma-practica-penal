<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CrearSimulacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'id_analisis' => ['required', 'integer', 'min:1'],
            'id_tipo_audiencia' => ['required', 'integer', 'min:1'],
        ];
    }
}
