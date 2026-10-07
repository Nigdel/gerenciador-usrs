<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Reactivación automática (Fase 2.2): las cuentas suspendidas con fecha de fin
 * vencida vuelven a estado activo sin intervención manual.
 *
 * withoutOverlapping evita que dos pasadas se pisen si una tanda de subsistemas
 * lentas dura más que el intervalo; onOneServer evita que se ejecuten en cada
 * contenedor a la vez. Los dos son relevantes en cuanto haya más de una
 * instancia.
 */
Schedule::command('accounts:reactivate-expired')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Suspensión programada (Fase 2.3): aplica las suspensiones agendadas
 * (estado 'pendiente') cuando ya pasó su fecha de inicio.
 */
Schedule::command('accounts:apply-pending-suspensions')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Caducidad de los secretos del payload (Sprint 1.1): el payload cifrado de
 * una operación guarda la contraseña general, y `cerrar()` ya la borra al
 * completarse. Este comando cubre lo que queda —fallidas que nadie reintentó y
 * completadas anteriores a la poda— pasado `operations.secret_ttl_hours`.
 *
 * Cada hora basta: el TTL por defecto está en días, así que hacerlo más a
 * menudo solo consumiría consultas.
 */
Schedule::command('operations:prune-secrets')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Operaciones atascadas (Sprint 1.4): una operación 'en curso' sin movimiento
 * es una operación cuyo worker murió, y mientras siga así bloquea al usuario
 * para abrir cualquier otra (1.4). Este comando la cierra para que pueda
 * reintentarse.
 *
 * Cada quince minutos y no cada hora porque el bloqueo es visible para el
 * operador: son quince minutos de "esta operación no avanza" en lugar de una
 * hora. El coste es una consulta con índice sobre (estado, updated_at).
 */
Schedule::command('operations:expire-stuck')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
