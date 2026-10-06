<?php

namespace App\Services;

use App\Contracts\SubsystemServiceInterface;
use App\Enums\GestorUserStatus;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\UserSubsystemAccount;
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
 * deshabilitada, la fila local no se toca. El estado 'baja' del GestorUser se
 * escribe **después** y solo si no hubo ningún fallo, para no dejar a un
 * usuario marcado como de baja con accesos vivos.
 */
class UserOffboardingService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    /**
     * @return array{usuario: GestorUser, resultados: array<int, array>}
     */
    public function darDeBaja(GestorUser $gestorUser, string $motivo): array
    {
        if ($gestorUser->estaDadoDeBaja()) {
            throw new RuntimeException('El usuario ya está dado de baja.');
        }

        $cuentas = $gestorUser->subsystemAccounts()->with('subsystem')->get();

        $resultados = $cuentas
            ->map(fn (UserSubsystemAccount $cuenta) => $this->darDeBajaCuenta($cuenta))
            ->all();

        // El usuario solo se marca de baja si todas sus cuentas quedaron
        // efectivamente deshabilitadas. A medias es peor que no hacer nada:
        // el listado prometería un estado que no se cumple.
        $fallos = array_filter($resultados, fn (array $resultado) => ! $resultado['exito']);

        if ($fallos !== []) {
            return ['usuario' => $gestorUser->fresh(), 'resultados' => $resultados];
        }

        $gestorUser->update([
            'estado' => GestorUserStatus::Baja,
            'baja_at' => now(),
            'motivo_baja' => $motivo,
        ]);

        return ['usuario' => $gestorUser->fresh(), 'resultados' => $resultados];
    }

    /**
     * Devuelve al usuario a activo y reactiva sus cuentas.
     *
     * Es el camino inverso, y existe por la misma razón que la baja es
     * reversible: una baja equivocada no debería obligar a rehacer el alta.
     *
     * @return array{usuario: GestorUser, resultados: array<int, array>}
     */
    public function reactivar(GestorUser $gestorUser): array
    {
        if (! $gestorUser->estaDadoDeBaja()) {
            throw new RuntimeException('El usuario no está dado de baja.');
        }

        $resultados = $gestorUser->subsystemAccounts()
            ->with('subsystem')
            ->get()
            ->map(fn (UserSubsystemAccount $cuenta) => $this->reactivarCuenta($cuenta))
            ->all();

        $fallos = array_filter($resultados, fn (array $resultado) => ! $resultado['exito']);

        if ($fallos !== []) {
            return ['usuario' => $gestorUser->fresh(), 'resultados' => $resultados];
        }

        $gestorUser->update([
            'estado' => GestorUserStatus::Activo,
            'baja_at' => null,
            'motivo_baja' => null,
        ]);

        return ['usuario' => $gestorUser->fresh(), 'resultados' => $resultados];
    }

    /**
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    private function darDeBajaCuenta(UserSubsystemAccount $cuenta): array
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
    private function reactivarCuenta(UserSubsystemAccount $cuenta): array
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
