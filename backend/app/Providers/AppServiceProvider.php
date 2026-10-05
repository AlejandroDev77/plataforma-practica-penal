<?php

namespace App\Providers;

use App\Integrations\Intelligence\FastApiSimulationAgentGateway;
use App\Models\User;
use App\Modules\Simulations\Application\Contracts\SimulationAgentGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // También disponibles cuando Laravel carga rutas compiladas y omite los archivos de rutas.
        RateLimiter::for('practicas-regladas-generacion', fn (Request $r) => Limit::perMinute(5)->by('generacion:'.$r->user()->getAuthIdentifier()));
        RateLimiter::for('practicas-regladas-comandos', fn (Request $r) => Limit::perMinute(10)->by('comandos:'.$r->user()->getAuthIdentifier()));
        RateLimiter::for('practicas-regladas-proveedor', fn (Request $r) => Limit::perMinute(10)->by('proveedor:'.$r->user()->getAuthIdentifier()));

        ResetPassword::createUrlUsing(static function (User $user, string $token): string {
            $query = http_build_query(['email' => $user->email]);

            return rtrim((string) config('app.frontend_url'), '/').'/restablecer-contrasena/'.$token.'?'.$query;
        });
    }
}
