<?php

namespace App\Enums;

/**
 * Estado de una cuenta dentro de una operación (Fase 3.2).
 *
 * No es SubsystemAccountStatus: aquel dice en qué estado está la cuenta en el
 * subsistema, y este dice si el trabajo que la tenía que tocar se completó.
 * Una cuenta puede estar 'activo' en su subsistema y tener su fila de trabajo
 * en 'error' porque el subsistema no confirmó — que es lo que evita el
 * confirm-before-persist de siempre marcar el cambio.
 */
enum OperationAccountStatus: string
{
    case Pendiente = 'pendiente';
    case Ok = 'ok';
    case Error = 'error';

    /**
     * Reutiliza el icono de 'pendiente' de components/account-status.blade.php
     * (el de reloj) a propósito: es la misma idea —algo que aún no ha
     * ocurrido— y evita tener que añadir una clase CSS nueva, que un
     * `npm run build` borraría de todos modos.
     */
    public function icono(): string
    {
        return match ($this) {
            self::Pendiente => 'icon-clock',
            self::Ok => 'icon-check',
            self::Error => 'icon-ban',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::Pendiente => 'text-[#68756d]',
            self::Ok => 'text-green-700',
            self::Error => 'text-red-700',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Ok => 'Correcto',
            self::Error => 'Error',
        };
    }
}
