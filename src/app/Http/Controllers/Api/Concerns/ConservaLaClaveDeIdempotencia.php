<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\IdempotencyKey;
use App\Models\ProvisioningOperation;

/**
 * Enlaza la clave de idempotencia con la operación que creó la petición.
 *
 * Vive en un trait, y no en el middleware, porque el middleware corre antes
 * que la acción: cuando él termina no sabe todavía si la operación se llegó a
 * crear. Quien lo sabe es el controlador, y las dos acciones que crean
 * operaciones hacen exactamente lo mismo en ese punto.
 */
trait ConservaLaClaveDeIdempotencia
{
    private function enlazaLaClave(ProvisioningOperation $operacion): void
    {
        $clave = request()->header('Idempotency-Key');

        // Sin cabecera no hay fila: la petición se ejecutó igualmente, sin
        // garantía de reintento, que es lo que se pidió.
        if (! is_string($clave) || trim($clave) === '') {
            return;
        }

        $fila = IdempotencyKey::where('key', trim($clave))->first();

        $fila?->enlazarOperacion($operacion->id);
    }
}
