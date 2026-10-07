<?php

namespace App\Enums;

/**
 * Estado global de una operación (Fase 3.2).
 *
 * Es distinto del estado de cada cuenta (OperationAccountStatus): una
 * operación con tres cuentas puede estar 'fallida' aunque dos estén 'ok', que
 * es justo el caso que el operador necesita ver de un vistazo.
 */
enum OperationStatus: string
{
    case Pendiente = 'pendiente';
    case EnCurso = 'en_curso';
    case Completada = 'completada';
    case Fallida = 'fallida';

    /**
     * Una operación terminada es una que ya no va a cambiar porque no queda
     * ninguna cuenta pendiente. Es la condición que usa el polling del panel
     * para dejar de consultar.
     */
    public function terminada(): bool
    {
        return $this === self::Completada || $this === self::Fallida;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnCurso => 'En curso',
            self::Completada => 'Completada',
            self::Fallida => 'Con errores',
        };
    }
}
