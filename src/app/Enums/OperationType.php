<?php

namespace App\Enums;

/**
 * Tipo de operación que sale a los subsistemas (Fase 3.2).
 *
 * Cada caso corresponde a un método por cuenta de uno de los orquestadores. El
 * job ProcessOperationAccount despacha sobre este valor, así que añadir un caso
 * aquí obliga a resolverlo también en ese job: es a propósito que quede
 * explícito y no se resuelva por reflexión.
 */
enum OperationType: string
{
    case Alta = 'alta';
    case Suspension = 'suspension';
    case Baja = 'baja';
    case Reactivacion = 'reactivacion';
    case Sincronizacion = 'sincronizacion';

    /**
     * Texto para el operador, en el panel de operaciones de la ficha.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Alta => 'Alta',
            self::Suspension => 'Suspensión',
            self::Baja => 'Baja',
            self::Reactivacion => 'Reactivación',
            self::Sincronizacion => 'Sincronización de datos',
        };
    }

    /**
     * Aviso de que el trabajo se ha encolado y aún no ha terminado.
     *
     * Importa que no suene a que ya está hecho: desde la Fase 3.2 la respuesta
     * llega antes de que ninguna cuenta se haya tocado, y un "Suspendido
     * correctamente" en ese momento sería mentira.
     */
    public function aviso(): string
    {
        return match ($this) {
            self::Alta => 'El alta en los subsistemas está en curso',
            self::Suspension => 'La suspensión está en curso',
            self::Baja => 'La baja está en curso',
            self::Reactivacion => 'La reactivación está en curso',
            self::Sincronizacion => 'La sincronización está en curso',
        };
    }

    /**
     * Ability de la policy que autoriza reintentar una cuenta de este tipo.
     *
     * Reintentar es repetir la acción original, así que se autoriza con la
     * misma regla que la autorizó la primera vez y no con una propia: un
     * reintento de una baja no puede poder hacer quien no puede dar de baja.
     *
     * Se resuelve con match y no por convención de nombres a propósito: un
     * caso nuevo sin resolver aquí sale corriendo en vez de autorizar de más.
     */
    public function ability(): string
    {
        return match ($this) {
            self::Alta => 'create',
            self::Suspension => 'suspend',
            self::Baja => 'offboard',
            self::Reactivacion, self::Sincronizacion => 'update',
        };
    }

    /**
     * Los dos tipos cuyo desenlace cambia además el estado del GestorUser.
     *
     * El alta, la suspensión y la sincronización no lo tocan: suspender una
     * cuenta no suspende al usuario. Y marcarlo de baja depende de que TODAS
     * las cuentas queden deshabilitadas, cosa que un job por cuenta no puede
     * saber; de eso se encarga ProvisioningOperationService::cerrar().
     *
     * @return array<int, self>
     */
    public static function queCambianElEstadoDelUsuario(): array
    {
        return [self::Baja, self::Reactivacion];
    }
}
