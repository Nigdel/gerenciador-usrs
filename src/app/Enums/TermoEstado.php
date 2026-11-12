<?php

namespace App\Enums;

/**
 * Ciclo de vida de un termo.
 *
 * Los nombres coinciden con los que usaba el plugin de GLPI (generado,
 * enviado, firmado) para que el histórico que ya exista allí se pueda leer sin
 * traducciones. "anulado" es propio de esta implementación: el plugin no tenía
 * forma de sacar un termo emitido por error.
 */
enum TermoEstado: string
{
    case Generado = 'generado';
    case Enviado = 'enviado';
    case Firmado = 'firmado';
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Generado => 'Generado',
            self::Enviado => 'Enviado',
            self::Firmado => 'Firmado',
            self::Anulado => 'Anulado',
        };
    }

    /**
     * Un termo vigente es el que todavía representa la entrega del medio. Se
     * usa para impedir que un mismo equipo tenga dos termos activos a la vez.
     */
    public function esVigente(): bool
    {
        return $this !== self::Anulado;
    }

    /**
     * Solo se puede recibir el PDF firmado mientras el termo esté pendiente.
     * Un termo ya firmado o anulado no se reemplaza: se anula y se emite otro.
     */
    public function permiteSubirFirmado(): bool
    {
        return $this === self::Generado || $this === self::Enviado;
    }

    public function permiteAnular(): bool
    {
        // Un termo firmado también se puede anular: si se firmó por error o el
        // equipo se reasigna, de lo contrario bloquearía para siempre la
        // emisión de un termo nuevo. Anular no borra el documento firmado.
        return $this !== self::Anulado;
    }
}
