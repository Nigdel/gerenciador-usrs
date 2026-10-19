<?php

namespace App\Services;

use App\Contracts\SubsystemServiceInterface;
use App\Enums\SubsystemAccountStatus;
use App\Exceptions\ProvisioningException;
use App\Models\GestorUser;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Da de baja a un usuario gestionado (Fase 2.6).
 *
 * La baja **deshabilita** sus cuentas en todos los subsistemas; nunca las
 * borra. Es una decisión deliberada y va en contra de lo que se podría hacer
 * con un driver que soporta deleteUser():
 *
 *  - Es reversible. Una baja equivocada se corrige reactivando; un
 *    ldap_delete() no tiene vuelta atrás.
 *  - Deja rastro en el directorio. Si el mismo CPF vuelve a la empresa dentro
 *    de un año, se reactiva en lugar de perder el histórico de qué tenía.
 *  - La propia aplicación ya borra la fila de user_subsystem_accounts cuando
 *    el usuario elimina una cuenta a mano, así que el histórico de qué se le
 *    hizo no depende de que el subsistema lo conserve.
 *
 * Cada cuenta se confirma contra el subsistema (mismo patrón confirm-before-
 * persist que la suspensión): si el subsistema no confirma que la cuenta está
 * deshabilitada, la fila local no se toca.
 *
 * Desde la Fase 3.2 este servicio ya **no itera cuentas**: eso lo hace un job
 * por cuenta. Aquí quedan las precondiciones y las acciones individuales:
 *
 *  - las precondiciones (validarBaja()), que el controlador comprueba antes de
 *    encolar para que el operador siga viendo el error en el momento, y
 *  - los métodos por cuenta de baja, suspensión del gestor y reactivación.
 *
 * Los estados de baja y suspensión del GestorUser los escribe
 * ProvisioningOperationService::cerrar(), porque dependen de que TODAS las
 * cuentas hayan salido bien y un job por cuenta no puede saberlo.
 */
class UserOffboardingService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    /**
     * Precondición de la baja. Se comprueba antes de encolar, no en el job: si
     * el usuario ya está dado de baja, el operador tiene que enterarse con el
     * formulario delante, no horas después en un job que falla.
     */
    public function validarBaja(GestorUser $gestorUser): void
    {
        if ($gestorUser->estaDadoDeBaja()) {
            throw new ProvisioningException('El usuario ya está dado de baja.');
        }

        if ($gestorUser->estaSuspendido()) {
            throw new ProvisioningException('Reactiva al gestor antes de darlo de baja.');
        }

        if ($gestorUser->cuentasSuspendidasPorGestor()->exists()) {
            throw new ProvisioningException('Resuelve la suspensión anterior del gestor antes de darlo de baja.');
        }
    }

    /**
     * Precondición de la reactivación, simétrica a validarBaja().
     */
    public function validarReactivacion(GestorUser $gestorUser): void
    {
        if (! $gestorUser->estaDadoDeBaja()) {
            throw new ProvisioningException('El usuario no está dado de baja.');
        }
    }

    /**
     * Cuentas a deshabilitar: todas las que tenga, sin importar el slug. La
     * baja no se puede limitar a un subconjunto de subsistemas.
     *
     * @return Collection<int, UserSubsystemAccount>
     */
    public function cuentasABajas(GestorUser $gestorUser): Collection
    {
        return $gestorUser->subsystemAccounts()->with('subsystem')->get();
    }

    /**
     * Cuentas a reactivar: solo las que están marcadas como eliminadas. Las
     * demás no necesitan nada y reactivarlas sería una llamada de más.
     *
     * @return Collection<int, UserSubsystemAccount>
     */
    public function cuentasAReactivar(GestorUser $gestorUser): Collection
    {
        return $gestorUser->subsystemAccounts()
            ->with('subsystem')
            ->where('estado', SubsystemAccountStatus::Borrado)
            ->get();
    }

    /**
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    public function darDeBajaCuenta(UserSubsystemAccount $cuenta): array
    {
        $subsistema = $cuenta->subsystem;

        // Una cuenta ya borrada no tiene nada que deshabilitar. No se cuenta
        // como fallo: el objetivo (que no exista acceso) ya se cumple.
        if ($cuenta->estado === SubsystemAccountStatus::Borrado) {
            return $this->resultado($cuenta, true, 'La cuenta ya estaba eliminada en el subsistema.');
        }

        $servicio = $this->resolver($cuenta);

        if ($servicio === null) {
            return $this->resultado($cuenta, false, 'No hay ningún driver registrado para el subsistema '.$subsistema->slug.'.');
        }

        try {
            $resultado = $servicio->disableUser($cuenta);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resultado($cuenta, false, $exception->getMessage());
        }

        if (! $resultado->success) {
            return $this->resultado($cuenta, false, $resultado->mensaje ?? 'El subsistema no deshabilitó la cuenta.');
        }

        $confirmado = $this->confirmar($servicio, $cuenta, ['deshabilitado', 'suspendido'], $resultado->estado);

        if (! $confirmado) {
            return $this->resultado($cuenta, false, 'El subsistema no confirmó que la cuenta quedara deshabilitada.');
        }

        // Se marca 'borrado' y no 'deshabilitado' porque para el usuario final
        // la diferencia es la misma (no entra) pero el histórico del 3.1 lo
        // distingue de un simple bloqueo.
        $cuenta->update([
            'estado' => SubsystemAccountStatus::Borrado,
            'inicio_suspension' => null,
            'fin_suspension' => null,
            'motivo_suspension' => null,
            'meta' => $resultado->raw,
        ]);

        return $this->resultado($cuenta, true, 'Cuenta deshabilitada en el subsistema.');
    }

    /**
     * Deshabilita una cuenta que estaba activa al suspender al gestor.
     *
     * A diferencia de la baja completa, conserva la cuenta como deshabilitada
     * en vez de marcarla como borrada y no elimina datos de suspensión propios.
     *
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    public function suspenderCuentaPorGestor(UserSubsystemAccount $cuenta): array
    {
        if ($cuenta->estado !== SubsystemAccountStatus::Activo) {
            return $this->resultado($cuenta, true, 'La cuenta ya no estaba activa; se conservó su estado actual.');
        }

        $servicio = $this->resolver($cuenta);

        if ($servicio === null) {
            return $this->resultado($cuenta, false, 'No hay ningún driver registrado para el subsistema '.$cuenta->subsystem?->slug.'.');
        }

        try {
            $resultado = $servicio->disableUser($cuenta);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resultado($cuenta, false, $exception->getMessage());
        }

        if (! $resultado->success) {
            return $this->resultado($cuenta, false, $resultado->mensaje ?? 'El subsistema no deshabilitó la cuenta.');
        }

        if (! $this->confirmar($servicio, $cuenta, ['deshabilitado', 'suspendido'], $resultado->estado)) {
            return $this->resultado($cuenta, false, 'El subsistema no confirmó que la cuenta quedara deshabilitada.');
        }

        $cuenta->update([
            'estado' => SubsystemAccountStatus::Deshabilitado,
            'meta' => $resultado->raw,
        ]);

        return $this->resultado($cuenta, true, 'Cuenta deshabilitada por la suspensión del gestor.');
    }

    /**
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    public function reactivarCuenta(UserSubsystemAccount $cuenta): array
    {
        $subsistema = $cuenta->subsystem;

        if (! in_array($cuenta->estado, [SubsystemAccountStatus::Borrado, SubsystemAccountStatus::Deshabilitado], true)) {
            return $this->resultado($cuenta, true, 'La cuenta no requiere reactivación.');
        }

        $servicio = $this->resolver($cuenta);

        if ($servicio === null) {
            return $this->resultado($cuenta, false, 'No hay ningún driver registrado para el subsistema '.$subsistema->slug.'.');
        }

        try {
            $resultado = $servicio->reactivateUser($cuenta);
        } catch (Throwable $exception) {
            report($exception);

            return $this->resultado($cuenta, false, $exception->getMessage());
        }

        if (! $resultado->success) {
            return $this->resultado($cuenta, false, $resultado->mensaje ?? 'El subsistema no reactivó la cuenta.');
        }

        // Cuando el subsistema devuelve un externalAccountId se apunta el id
        // nuevo **antes** de confirmar, porque la confirmación se consulta sobre
        // él: hay drivers (Chatwoot) cuya reactivación crea el usuario de nuevo,
        // así que consultar el id anterior daría 404 y la reactivación se
        // reportaría como fallida aunque haya funcionado. Solo se cambia en
        // memoria; la escritura en la BD viene después, si la operación se
        // confirma, como en el resto de los casos.
        $atributos = $cuenta->atributosAlReactivar() + ['meta' => $resultado->raw];

        // La configuración guardada de la cuenta (cajas de entrada y equipos) se
        // actualiza con lo que el subsistema devuelve al reactivar: si el
        // agente se tuvo que recrear, es la que dejó el alta nueva.
        if ($resultado->configuracionAdicional !== []) {
            $atributos['configuracion_adicional'] = $resultado->configuracionAdicional;
        }

        if (! blank($resultado->externalAccountId)) {
            $atributos['external_account_id'] = $resultado->externalAccountId;
            $cuenta->external_account_id = $resultado->externalAccountId;
        }

        $confirmado = $this->confirmar($servicio, $cuenta, ['activo'], $resultado->estado);

        if (! $confirmado) {
            return $this->resultado($cuenta, false, 'El subsistema no confirmó que la cuenta quedara activa.');
        }

        // atributosAlReactivar() (Fase 2.5) deja la cuenta en 'activo' y limpia
        // el rastro de la suspensión; se reutiliza en vez de repetirlo aquí.
        $cuenta->update($atributos);

        return $this->resultado($cuenta, true, 'Cuenta reactivada en el subsistema.');
    }

    /**
     * Confirma que la operación se aplicó de verdad en el subsistema.
     *
     * La confirmación normal es consultar el estado remoto de la cuenta. Pero
     * hay drivers que no pueden verificarlo —Chatwoot no tiene estados de
     * suspensión o deshabilitado, desactivar es quitarle el acceso al
     * agente— y en ese caso se confirma con el estado que devuelve la propia
     * operación, que es el único dato que hay.
     *
     * @param  array<int, string>  $estadosAceptados
     */
    private function confirmar(mixed $servicio, UserSubsystemAccount $cuenta, array $estadosAceptados, ?string $estadoOperacion = null): bool
    {
        if (is_callable([$servicio, 'verificaEstadoRemoto'])
            && ! call_user_func([$servicio, 'verificaEstadoRemoto'])) {
            return $estadoOperacion !== null
                && in_array($estadoOperacion, $estadosAceptados, true);
        }

        $estadoRemoto = $servicio->getUserStatus($cuenta);

        return $estadoRemoto->success
            && in_array($estadoRemoto->estado, $estadosAceptados, true);
    }

    private function resolver(UserSubsystemAccount $cuenta): ?SubsystemServiceInterface
    {
        $subsistema = $cuenta->subsystem;

        if ($subsistema === null) {
            return null;
        }

        try {
            return $this->registry->resolve($subsistema->slug);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    private function resultado(UserSubsystemAccount $cuenta, bool $exito, ?string $mensaje): array
    {
        return [
            'subsistema' => $cuenta->subsystem?->slug,
            'exito' => $exito,
            'mensaje' => $mensaje,
            'cuenta' => $cuenta->fresh(),
        ];
    }
}
