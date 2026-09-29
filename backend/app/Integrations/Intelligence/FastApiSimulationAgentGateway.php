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
}
