<?php

namespace App\Modules\Simulations\Application\Contracts;

use App\Integrations\Intelligence\FastApiAgenteRegladoGateway;
use Illuminate\Container\Attributes\Bind;

#[Bind(FastApiAgenteRegladoGateway::class)]
interface AgenteRegladoGateway
{
    /** @return array{provider: string, model: string, requires_external_authorization: bool} */
    public function proveedor(): array;

    /** @param array<string, mixed> $contexto @return array<string, mixed> */
    public function proponer(array $contexto): array;
}
