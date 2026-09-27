<?php

namespace App\Providers;

use App\Integrations\Intelligence\FastApiSimulationAgentGateway;
use App\Models\User;
use App\Modules\Simulations\Application\Contracts\SimulationAgentGateway;
use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::createUrlUsing(static function (User $user, string $token): string {
            $query = http_build_query(['email' => $user->email]);

            return rtrim((string) config('app.frontend_url'), '/').'/restablecer-contrasena/'.$token.'?'.$query;
        });
    }
}
