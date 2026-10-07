<?php

namespace App\Exceptions;

use App\Models\ProvisioningOperation;
use RuntimeException;

/**
 * Ya hay una operación sin terminar para este usuario (Sprint 1.4).
 *
 * Una operación en curso está tocando sus subsistemas ahora mismo. Encadenar
 * otra sobre la misma persona no es solo ruido: dos jobs pueden llegar al mismo
 * subsistema a la vez y pelearse por la misma cuenta, y el que pierda deja el
 * estado local mintiendo sobre el remoto — que es exactamente la discrepancia
 * que el Sprint 5 luego tiene que conciliar a mano.
 *
 * Extiende RuntimeException para que los `catch (RuntimeException)` que ya hay
 * en los controladores la traten como lo que es —un error de dominio, no una
 * avería— sin tocar su firma.
 *
 * Lleva la operación activa dentro a propósito: la API responde 409 con su
 * `operacion_id` para que el cliente pueda consultarla y no solo reintentar a
 * ciegas.
 */
class OperationInProgressException extends RuntimeException
{
    public function __construct(
        public readonly ?ProvisioningOperation $operacion = null,
    ) {
        parent::__construct($operacion === null
            ? 'Ya hay una operación en curso para este usuario.'
            : sprintf(
                'Ya hay una operación en curso para este usuario (%s, iniciada el %s).',
                $operacion->tipo->etiqueta(),
                $operacion->iniciada_at?->format('d/m/Y H:i')
                    ?? $operacion->created_at->format('d/m/Y H:i'),
            ));
    }
}
