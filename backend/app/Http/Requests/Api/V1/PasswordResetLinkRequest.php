<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PasswordResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:255']];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Ingresa un correo electrónico válido.',
        ];
    }
}
