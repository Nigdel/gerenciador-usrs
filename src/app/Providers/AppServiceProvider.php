<?php

namespace App\Providers;

use App\Models\UserSubsystemAccount;
use App\Observers\AccountStateLogObserver;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Se registra siempre, también en consola: la reactivación automática
        // del scheduler es justo uno de los cambios que hay que poder auditar.
        UserSubsystemAccount::observe(AccountStateLogObserver::class);

        // La API se limita por integración, no por IP: cada integración tiene
        // su propia cuota para que una no consuma el cupo de las demás.
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(60)->by($this->apiLimiterKey($request)),
            Limit::perMinute(600)->by('api-global'),
        ]);
    }

    /**
     * Identifica al llamante por su token.
     *
     * Se usa el token crudo en lugar de $request->user() porque el middleware
     * throttle se ejecuta antes que auth:sanctum: en ese punto el usuario
     * todavía no está resuelto.
     */
    private function apiLimiterKey(Request $request): string
    {
        $token = $request->bearerToken();

        return $token !== null
            ? 'token:'.hash('sha256', $token)
            : 'web:'.($request->ip() ?? 'desconocida');
    }
}
