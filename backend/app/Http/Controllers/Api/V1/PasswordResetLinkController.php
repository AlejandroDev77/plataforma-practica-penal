<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PasswordResetLinkRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

final class PasswordResetLinkController extends Controller
{
    public function __invoke(PasswordResetLinkRequest $request): JsonResponse
    {
        Password::sendResetLink($request->safe()->only('email'));

        return response()->json([
            'message' => 'Si existe una cuenta con ese correo, enviaremos instrucciones para recuperar el acceso.',
        ]);
    }
}
