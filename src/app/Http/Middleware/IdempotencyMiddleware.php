<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotencia para las rutas que crean algo (Sprint 5.3).
 *
 * Va ANTES de la validación a propósito. Si la misma clave llega con un cuerpo
 * distinto, tiene que contestar 422 aunque el resto del payload sea válido: un
 * FormRequest no sabe nada de la clave, y si validara primero el conflicto se
 * perdería detrás de un «falta el campo nombre». El orden también evita
 * validar dos veces un cuerpo que ya se sabe que va a rechazarse.
 *
 * Sin cabecera no cambia nada: la idempotencia es optativa, para no obligar a
 * las integraciones que nunca reintentan a inventar una clave.
 */
class IdempotencyMiddleware
{
    private const CABECERA = 'Idempotency-Key';

    public function __construct(private readonly IdempotencyService $servicio) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clave = $this->clave($request);

        if ($clave === null) {
            return $next($request);
        }

        $decision = $this->servicio->reservar($request, $clave);

        $respuesta = match ($decision['estado']) {
            IdempotencyService::REPETIR => $this->servicio->repetir($decision['fila']),
            IdempotencyService::EN_CURSO => $this->enCurso(),
            IdempotencyService::CONFLICTO => $this->conflicto(),
            default => $this->ejecutar($request, $next, $decision['fila']),
        };

        // Siempre se devuelve la misma clave, y se añade también a la primera
        // respuesta: así la integración puede distinguir la original de la
        // repetida leyendo la cabecera, sin comparar cuerpos.
        // `repetir()` devuelve un Response pelado —el cuerpo se reenvía byte a
        // byte—, y ese no tiene `withHeaders()`. Aquí se reconstruye la
        // respuesta final con las cabeceras ya unidas en los dos casos.
        return new Response(
            $respuesta->getContent(),
            $respuesta->getStatusCode(),
            $respuesta->headers->all() + [self::CABECERA => [$clave]],
        );
    }

    /**
     * Ejecuta la petición de verdad y guarda lo que respondió.
     *
     * @param  Closure(Request): (Response)  $next
     */
    private function ejecutar(Request $request, Closure $next, $fila): Response
    {
        $respuesta = $next($request);
        $cuerpo = (string) $respuesta->getContent();

        // Un 4xx libera la clave (guardarError la borra) y uno de 5xx la
        // guarda con su error, para que repetir dé el mismo fallo y no una
        // segunda ejecución a ciegas.
        if ($respuesta->getStatusCode() >= 400) {
            $this->servicio->guardarError($fila, $respuesta->getStatusCode(), $cuerpo);

            return $respuesta;
        }

        // Se pasa el contenido ya serializado, no el objeto respuesta: un
        // stream no se lee dos veces, y lo que se guarda tiene que ser
        // exactamente lo que se envió para que la repetición sea idéntica
        // byte a byte.
        $this->servicio->guardarRespuesta($fila, $cuerpo, $respuesta->getStatusCode());

        return $respuesta;
    }

    /**
     * Normaliza la cabecera: sin clave no hay idempotencia.
     *
     * Una clave en blanco cuenta como ausente y no como una clave válida: un
     * espacio se guardaría tal cual y dos peticiones distintas acabarían
     * compartiendo fila.
     */
    private function clave(Request $request): ?string
    {
        $valor = $request->header(self::CABECERA);

        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : mb_substr($valor, 0, IdempotencyService::MAX_LONGTUD_CLAVE);
    }

    private function enCurso(): JsonResponse
    {
        // No es un 409: no hay conflicto con el estado del negocio, es que la
        // respuesta anterior todavía no existe. 425 (Too Early) es exactamente
        // eso, y reintentar un segundo después devuelve la respuesta ya
        // guardada.
        return new JsonResponse([
            'message' => 'Una petición con esta clave de idempotencia todavía se está procesando.',
            'retry_after' => 1,
        ], (int) config('idempotency.status_en_curso', 425));
    }

    private function conflicto(): JsonResponse
    {
        // 422 y no 409: el conflicto no es con el estado del negocio —el alta
        // no choca con nada— sino con una petición anterior que se envió bajo
        // la misma clave con otros datos. Es el mismo código que un payload no
        // válido porque el remedio es el mismo: mandar la clave original con su
        // cuerpo, o una clave nueva.
        return new JsonResponse([
            'message' => 'Esta clave de idempotencia ya se usó con un cuerpo distinto.',
        ], 422);
    }
}
