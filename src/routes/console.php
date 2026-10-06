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
