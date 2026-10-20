<?php

namespace App\Integrations\Intelligence;

use App\Modules\Simulations\Application\Contracts\AgenteRegladoGateway;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class FastApiAgenteRegladoGateway implements AgenteRegladoGateway
{
    public function proveedor(): array
    {
        $datos = $this->solicitar('get', '/api/v1/simulations/provider')->json('data');
        if (! is_array($datos) || ! in_array($datos['provider'] ?? null, ['ollama', 'openai'], true)
            || ! is_string($datos['model'] ?? null) || trim($datos['model']) === '' || mb_strlen($datos['model']) > 150
            || ($datos['requires_external_authorization'] ?? null) !== ($datos['provider'] === 'openai')) {
            throw new DomainException('El proveedor de prácticas no está disponible o no declara sus permisos.');
        }

        return $datos;
    }

    public function proponer(array $contexto): array
    {
        $respuesta = $this->solicitar('post', '/api/v1/simulations/proposals', $contexto);
        $datos = $respuesta->json('data');
        if (! is_array($datos)) {
            throw new DomainException('No se recibió un borrador verificable.');
        }

        return [...$datos, '_meta' => ['provider' => $respuesta->header('X-Jurissim-Simulation-Provider'),
            'model' => $respuesta->header('X-Jurissim-Simulation-Model')]];
    }

    private function solicitar(string $metodo, string $ruta, array $contexto = []): Response
    {
        try {
            return ($metodo === 'get' ? $this->cliente()->get($ruta) : $this->cliente()->post($ruta, $contexto))->throw();
        } catch (ConnectionException|RequestException) {
            throw new DomainException('No se pudo contactar con el servicio de prácticas. No se incorporó ninguna intervención.');
        }
    }

    private function cliente(): PendingRequest
    {
        return Http::baseUrl((string) config('services.intelligence.url'))->acceptJson()->asJson()
            ->when(config('services.intelligence.token'), fn ($r) => $r->withToken((string) config('services.intelligence.token')))
            ->timeout((int) config('services.intelligence.timeout'));
    }
}
