<?php

namespace App\Enums;

/**
 * Estado del usuario gestionado, independiente del estado de cada una de sus
 * cuentas en subsistemas (SubsystemAccountStatus).
 *
 * Son dos niveles distintos y no conviene mezclarlos: una persona puede estar
 * 'baja' aquí y tener una cuenta todavía activa en un subsistema al que la
 * baja no llegó, y eso es justo lo que la ficha tiene que poder explicar.
 */
enum GestorUserStatus: string
{
    case Activo = 'activo';

    /**
     * El usuario está de baja: todas sus cuentas están deshabilitadas en los
     * subsistemas y no debería volver a acceder. El estado es reversible con
     * UserOffboardingService::reactivar(); no se borra nada.
     */
    case Baja = 'baja';
}
