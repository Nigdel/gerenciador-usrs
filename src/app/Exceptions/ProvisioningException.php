<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de dominio: la petición era válida pero el estado del sistema no
 * permite ejecutarla (Sprint 2.1).
 *
 * Es la única clase cuyo mensaje se enseña al operador tal cual. Lo que llega
 * aquí describe una situación que un humano entiende y sobre la que puede
 * actuar —"el usuario ya está dado de baja"— y que además no dice nada de la
 * infraestructura.
 *
 * Cualquier otra excepción significa que algo se rompió por dentro, y su
 * mensaje se queda en el log: ponerlo delante del usuario convertiría un
 * `TypeError` con un nombre de columna en una pantalla de detalles internos.
 */
class ProvisioningException extends RuntimeException {}
