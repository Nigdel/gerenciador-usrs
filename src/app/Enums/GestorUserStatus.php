<?php

namespace App\Enums;

/**
 * Estado del usuario gestionado, independiente del estado de cada una de sus
 * cuentas en subsistemas (SubsystemAccountStatus).
 *
 * 'suspendido' representa una suspensión temporal del gestor; 'baja' representa
 * el fin del vínculo. Ninguno de esos valores se deduce de los estados de cuenta.
 */
enum GestorUserStatus: string
{
    case Activo = 'activo';

    case Suspendido = 'suspendido';

    /**
     * El vínculo laboral terminó. Este estado corresponde a la baja completa,
     * no a una suspensión temporal del gestor.
     */
    case Baja = 'baja';
}
