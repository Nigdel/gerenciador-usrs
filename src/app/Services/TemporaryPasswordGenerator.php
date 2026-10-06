<?php

namespace App\Services;

/**
 * Generador de contraseñas temporales.
 *
 * Vive aparte para que el alta (SambaAD) y el restablecimiento generen exactamente
 * el mismo tipo de contraseña, en vez de dos generadores que se van pareciendo
 * con el tiempo.
 */
class TemporaryPasswordGenerator
{
    /**
     * Formato idéntico al que ya usaba SambaAD ('Klios#' + 8 hex + '!').
     *
     * Los 8 caracteres hex son 4 bytes de random_bytes(): suficiente entropía
     * para una credencial temporal y legible de dictar por teléfono. Los dos
     * símbolos (# y !) no están por decoración: Samba/AD rechaza contraseñas que
     * no cumplan complexity.
     */
    public function generar(): string
    {
        return 'Klios#'.bin2hex(random_bytes(4)).'!';
    }
}
