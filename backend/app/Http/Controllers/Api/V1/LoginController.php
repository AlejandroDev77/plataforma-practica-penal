<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->safe()->only(['email', 'password']))) {
            throw ValidationException::withMessages([
                'email' => ['El correo o la contraseña no son correctos.'],
            ]);
        }

        $request->session()->regenerate();

        return AuthenticatedUserResource::make($request->user())->response();
    }
}
