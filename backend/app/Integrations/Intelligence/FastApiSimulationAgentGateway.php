<?php

namespace App\Integrations\Intelligence;

use App\Modules\Simulations\Application\Contracts\SimulationAgentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class FastApiSimulationAgentGateway implements SimulationAgentGateway
{
    public function proposeAction(array $context): array
    {
        $response = $this->client()
            ->post('/api/v1/simulations/proposals', $context)
            ->throw();
        $proposal = $response->json('data');

        if (! is_array($proposal)) {
            throw new RuntimeException('El servicio local no devolvió una propuesta válida.');
        }

        return [
            ...$proposal,
            '_meta' => [
                'provider' => $response->header('X-Jurissim-Simulation-Provider'),
                'model' => $response->header('X-Jurissim-Simulation-Model'),
            ],
        ];
    }

    private function client(): PendingRequest
    {
        $token = config('services.intelligence.token');

        return Http::baseUrl((string) config('services.intelligence.url'))
            ->acceptJson()
            ->asJson()
            ->when($token, fn (PendingRequest $request) => $request->withToken((string) $token))
            ->timeout((int) config('services.intelligence.timeout'));
    }

    public function provider(): array
    {
        $data = $this->client()->get('/api/v1/simulations/provider')->throw()->json('data');
        if (! is_array($data)
            || ! in_array($data['provider'] ?? null, ['disabled', 'ollama', 'openai'], true)
            || ! array_key_exists('model', $data)
            || ($data['model'] !== null && (! is_string($data['model']) || mb_strlen($data['model']) > 150))
            || ($data['requires_external_authorization'] ?? null) !== ($data['provider'] === 'openai')) {
            throw new RuntimeException('El servicio no devolvió un proveedor válido.');
        }

        return [
            'provider' => $data['provider'],
            'model' => $data['model'],
            'requires_external_authorization' => $data['requires_external_authorization'],
        ];
    }
}
