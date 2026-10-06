<?php

namespace App\Services;

use App\Contracts\SubsystemServiceInterface;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Collection;
use RuntimeException;
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
 * por cuenta. Aquí quedan solo dos cosas, y ambas por lo que no se puede
 * hacer desde un job:
 *
 *  - las precondiciones (validarBaja()), que el controlador comprueba antes de
 *    encolar para que el operador siga viendo el error en el momento, y
 *  - el método por cuenta, que el job llama una vez por cada una.
 *
 * El estado 'baja' del GestorUser lo escribe ProvisioningOperationService::cerrar(),
 * porque depende de que TODAS las cuentas hayan salido bien y un job por
 * cuenta no puede saberlo.
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
            throw new RuntimeException('El usuario ya está dado de baja.');
        }
    }

    /**
     * Precondición de la reactivación, simétrica a validarBaja().
     */
    public function validarReactivacion(GestorUser $gestorUser): void
    {
        if (! $gestorUser->estaDadoDeBaja()) {
            throw new RuntimeException('El usuario no está dado de baja.');
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

        $confirmado = $this->confirmar($servicio, $cuenta, ['deshabilitado', 'suspendido']);

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
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    public function reactivarCuenta(UserSubsystemAccount $cuenta): array
    {
        $subsistema = $cuenta->subsystem;

        if ($cuenta->estado !== SubsystemAccountStatus::Borrado) {
            return $this->resultado($cuenta, true, 'La cuenta ya estaba activa.');
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

        if (! blank($resultado->externalAccountId)) {
            $atributos['external_account_id'] = $resultado->externalAccountId;
            $cuenta->external_account_id = $resultado->externalAccountId;
        }

        $confirmado = $this->confirmar($servicio, $cuenta, ['activo']);

        if (! $confirmado) {
            return $this->resultado($cuenta, false, 'El subsistema no confirmó que la cuenta quedara activa.');
        }

        // atributosAlReactivar() (Fase 2.5) deja la cuenta en 'activo' y limpia
        // el rastro de la suspensión; se reutiliza en vez de repetirlo aquí.
        $cuenta->update($atributos);

        return $this->resultado($cuenta, true, 'Cuenta reactivada en el subsistema.');
    }

    /**
     * Consulta el estado remoto para no dar por buena una operación que el
     * subsistema aceptó pero no aplicó.
     */
    private function confirmar(mixed $servicio, UserSubsystemAccount $cuenta, array $estadosAceptados): bool
    {
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