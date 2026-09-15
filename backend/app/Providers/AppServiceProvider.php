<?php

namespace App\Providers;

use App\Integrations\Intelligence\FastApiSimulationAgentGateway;
use App\Modules\Simulations\Application\Contracts\SimulationAgentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            SimulationAgentGateway::class,
            FastApiSimulationAgentGateway::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
