<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class RevisarAnalisisRequest extends FormRequest
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
            'decision' => ['required', 'in:aprobado,requiere_cambios'],
            'observacion' => ['required_if:decision,requiere_cambios', 'nullable', 'string', 'max:2000'],
        ];
    }
}
