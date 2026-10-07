<?php

use App\Exceptions\OperationInProgressException;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();

        // Sanctum no registra estos alias: vienen del mapa por defecto del
        // framework, que se salta cuando los providers se declaran a mano.
        $middleware->alias([
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sprint 1.4: se maneja aquí y no en cada acción porque el rechazo no
        // es un error de esa acción concreta —afecta a las seis por igual— y
        // repetir el try/catch en cada una sería la vía fácil de olvidarlo en
        // la siguiente.
        $exceptions->render(function (OperationInProgressException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // 409 y no 400: el alta no está mal formada, hay un conflicto
                // con la operación anterior. Se devuelve su uuid porque la
                // integración ya tiene el endpoint del Sprint 1.3 para seguirla
                // y no tiene que adivinar a qué espera.
                return response()->json([
                    'message' => $exception->getMessage(),
                    'operacion_id' => $exception->operacion?->uuid,
                ], Response::HTTP_CONFLICT);
            }

            $usuario = $exception->operacion?->usuario;

            return redirect()
                ->route(
                    $usuario === null ? 'gestor-users.index' : 'gestor-users.show',
                    $usuario,
                )
                ->with('error', $exception->getMessage());
        });
    })
    ->withProviders([
        AuthServiceProvider::class,
    ])
    ->create();
