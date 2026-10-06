<?php

namespace App\Enums;

enum SubsystemAccountStatus: string
{
    case Activo = 'activo';

    /**
     * Suspensión con fecha de inicio futura: el subsistema todavía no se ha
     * tocado, pero ya hay una suspensión agendada. La aplica el scheduler
     * (accounts:apply-pending-suspensions) cuando llega la fecha.
     */
    case Pendiente = 'pendiente';
    case Deshabilitado = 'deshabilitado';
    case Suspendido = 'suspendido';
    case Borrado = 'borrado';
}
