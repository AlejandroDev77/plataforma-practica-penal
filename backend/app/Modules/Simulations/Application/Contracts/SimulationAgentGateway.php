<?php

namespace App\Modules\Simulations\Application\Contracts;

interface SimulationAgentGateway
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function proposeAction(array $context): array;
}
