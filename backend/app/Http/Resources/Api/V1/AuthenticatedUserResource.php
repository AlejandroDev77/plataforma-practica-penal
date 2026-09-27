<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, roles: array<int, string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->values()->all(),
        ];
    }
}
