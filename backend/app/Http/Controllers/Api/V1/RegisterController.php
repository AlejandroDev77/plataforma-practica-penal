<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->assignRole(Role::findOrCreate('estudiante', 'web'));

            return $user;
        });

        return AuthenticatedUserResource::make($user)->response()->setStatusCode(201);
    }
}
