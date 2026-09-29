<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SolicitarAnalisisRequest extends FormRequest
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
            'page_ids' => ['required', 'array', 'min:1', 'max:10'],
            'page_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
