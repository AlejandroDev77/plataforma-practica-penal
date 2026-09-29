<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class RegistrarIntervencionRequest extends FormRequest
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
            'contenido' => ['required', 'string', 'max:20000', 'regex:/\S/u'],
            'source_fragment_ids' => ['sometimes', 'array', 'list', 'max:10'],
            'source_fragment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
