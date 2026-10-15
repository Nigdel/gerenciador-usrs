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
    case SuspensionGestor = 'suspension_gestor';
    case Baja = 'baja';
    case Reactivacion = 'reactivacion';
    case ReactivacionGestor = 'reactivacion_gestor';
    case Sincronizacion = 'sincronizacion';
    case ResetPassword = 'reset_password';

    /**
     * Texto para el operador, en el panel de operaciones de la ficha.
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Alta => 'Alta',
            self::Suspension => 'Suspensión',
            self::SuspensionGestor => 'Suspensión del gestor',
            self::Baja => 'Baja',
            self::Reactivacion => 'Reactivación',
            self::ReactivacionGestor => 'Reactivación del gestor',
            self::Sincronizacion => 'Sincronización de datos',
            // En minúsculas y con guion a propósito: a diferencia de los otros
            // cinco, este nunca lo ve un operador en un botón —nace ya
            // cerrada— sino en el listado de operaciones, que es texto en
            // Spanish y donde 'Restablecimiento de contraseña' pesa más que el
            // resto.
            self::ResetPassword => 'Restablecimiento de contraseña',
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
            self::SuspensionGestor => 'La suspensión del gestor está en curso',
            self::Baja => 'La baja está en curso',
            self::Reactivacion => 'La reactivación está en curso',
            self::ReactivacionGestor => 'La reactivación del gestor está en curso',
            self::Sincronizacion => 'La sincronización está en curso',
            // No hay aviso porque nunca se encola: ver el docblock.
            self::ResetPassword => 'El restablecimiento está en curso',
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
            self::SuspensionGestor => 'suspendManager',
            self::Baja => 'offboard',
            self::Reactivacion, self::Sincronizacion => 'update',
            self::ReactivacionGestor => 'reactivateManager',
            self::ResetPassword => 'resetPassword',
        };
    }

    /**
     * Los tipos cuyo desenlace cambia además el estado del GestorUser.
     *
     * Las operaciones por cuenta no cambian el estado del gestor. Las
     * operaciones de gestor esperan a que todas las cuentas terminen.
     *
     * @return array<int, self>
     */
    public static function queCambianElEstadoDelUsuario(): array
    {
        return [self::SuspensionGestor, self::Baja, self::Reactivacion, self::ReactivacionGestor];
    }
}
