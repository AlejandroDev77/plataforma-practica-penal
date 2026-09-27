<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset($request->validated(), function ($user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [$status === Password::INVALID_TOKEN
                    ? 'El enlace venció o ya fue utilizado. Solicita uno nuevo.'
                    : 'No encontramos una cuenta asociada a ese correo.'],
            ]);
        }

        return response()->json(['message' => 'Tu contraseña se actualizó. Ya puedes iniciar sesión.']);
    }
}
