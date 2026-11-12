<?php

namespace App\Enums;

/**
 * Tipo de termo. El plugin manejaba entrega, devolução e transferência; se
 * conservan los tres porque la entrega no es el único movimiento posible de un
 * medio y el documento tiene que decir cuál es.
 */
enum TermoTipo: string
{
    case Entrega = 'entrega';
    case Devolucion = 'devolucion';
    case Transferencia = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrega => 'Entrega',
            self::Devolucion => 'Devolução',
            self::Transferencia => 'Transferência',
        };
    }

    public function tituloDocumento(): string
    {
        return match ($this) {
            self::Entrega => 'TERMO DE ENTREGA DE EQUIPAMENTO',
            self::Devolucion => 'TERMO DE DEVOLUÇÃO DE EQUIPAMENTO',
            self::Transferencia => 'TERMO DE TRANSFERÊNCIA DE EQUIPAMENTO',
        };
    }
}
