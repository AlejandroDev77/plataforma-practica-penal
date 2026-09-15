<?php

namespace App\Integrations\Intelligence;

use App\Modules\Simulations\Application\Contracts\SimulationAgentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final readonly class FastApiSimulationAgentGateway implements SimulationAgentGateway
{
    public function proposeAction(array $context): array
    {
        return $this->client()
            ->post('/api/v1/simulations/proposals', $context)
            ->throw()
            ->json('data');
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
