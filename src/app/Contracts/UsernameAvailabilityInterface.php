<?php

namespace App\Contracts;

use App\Models\Subsystem;

/**
 * Contrato opcional (Fase 2.8): permite a un subsistema decir si un login ya
 * está ocupado, para que el generador no proponga uno que el alta rechazará.
 *
 * Va **aparte** de IdentityProviderInterface a propósito. `existsByEmail()` es
 * la búsqueda por CPF/email que hace el proveedor de identidad para *reutilizar*
 * un usuario que ya existe; esto es la comprobación de *disponibilidad* de un
 * login nuevo, que es una operación distinta y que la mayoría de los drivers
 * pueden hacer aunque no sean proveedores de identidad.
 *
 * Un driver que no lo implemente simplemente no se consulta: el generador lo
 * trata como "no comprobable", no como "libre" ni como "ocupado".
 */
interface UsernameAvailabilityInterface
{
    /**
     * Si el login ya está ocupado en este subsistema.
     *
     * El **null es parte del contrato** y es lo más importante de todo: es
     * "no se pudo comprobar" (caída, timeout, error de autenticación, falta de
     * configuración). No se puede leer como `false` porque entonces una caída
     * de Adagio haría creer al generador que cualquier login está libre, y el
     * alta reventaría más tarde con un error de duplicado. Quien llama decide
     * qué hacer con esa incertidumbre; en el generador (Fase 2.8) se propone el
     * login igualmente y se avisa, porque bloquear el alta de toda la
     * organización por una caída de un tercero es peor que proponer un login y
     * dejar constancia.
     *
     * @param  string  $login  Login sin dominio, ej. "juan.perez".
     * @param  string|null  $empresa  Empresa del alta, necesaria en los drivers
     *                                que resuelven configuración por empresa
     *                                (Entra ID, Chatwoot). Null si no aplica.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool;
}
