<?php

namespace App\Support;

use App\Models\User;

/**
 * Resuelve quién está provocando un cambio y desde qué contexto.
 *
 * Existe para que el observer del histórico no dependa de la petición: los
 * servicios lo llaman sin enterarse, y funciona igual en web, en API y —con
 * actor nulo— en el scheduler.
 */
class ActorContext
{
    private static ?User $forzado = null;

    /** Usuario explícito, si algún proceso (scheduler, comando) quiere atribuir su trabajo. */
    public static function registrar(User $user): void
    {
        self::$forzado = $user;
    }

    public static function olvidar(): void
    {
        self::$forzado = null;
    }

    public static function actual(): ?User
    {
        if (self::$forzado !== null) {
            return self::$forzado;
        }

        return auth()->user();
    }

    /**
     * Distingue los tres orígenes: una misma operación llega por la web, por la
     * API de integraciones o por el scheduler, y para auditar conviene saberlo.
     *
     * Se decide por la petición y no por runningInConsole(): la suite de tests
     * corre en consola pero sí monta peticiones HTTP de verdad, así que mirar
     * primero la consola clasificaría mal todo lo que llega por web o por API.
     */
    public static function origen(): string
    {
        $request = request();

        // Una petición creada a mano en consola no tiene método ni ruta, y eso
        // es justo lo que distingue al scheduler de una petición de verdad.
        if ($request === null || $request->getMethod() === '' || $request->path() === '/') {
            return 'scheduler';
        }

        return $request->is('api/*') || $request->expectsJson()
            ? 'api'
            : 'web';
    }
}
