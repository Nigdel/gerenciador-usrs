<?php

namespace App\Services;

use App\Enums\GestorUserStatus;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reactiva las cuentas suspendidas cuya suspensión ya venció.
 *
 * El criterio es `fin_suspension <= now()`: el campo queda ahí como registro
 * histórico de la suspensión (su limpieza es otra tarea, la 2.5). Como sólo se
 * reactivan las que están en estado 'suspendido', el paso es idempotente: una
 * cuenta ya reactivada no vuelve a entrar en la consulta.
 *
 * Solo se reactivan las cuentas de gestores **activos**: si el gestor fue
 * suspendido o dado de baja después, devolverle el acceso a una cuenta suelta
 * por haber vencido su propio plazo contradiría el estado del gestor.
 *
 * Igual que en UserSuspensionService, la escritura en la BD sólo ocurre si el
 * subsistema confirma el nuevo estado: un fallo remoto no debe dejar la cuenta
 * marcada como activa.
 */
class AccountReactivationService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly AccountStateConfirmationService $confirmaciones,
    ) {}

    /**
     * Cuentas suspendidas cuya suspensión ya venció y que tocan reactivar.
     *
     * Se expone separada de la ejecución para que el comando pueda listarlas
     * con --dry-run sin tocar nada.
     *
     * @return Collection<int, UserSubsystemAccount>
     */
    public function cuentasVencidas(): Collection
    {
        return UserSubsystemAccount::query()
            ->with(['subsystem', 'user'])
            ->where('estado', 'suspendido')
            ->whereNotNull('fin_suspension')
            ->where('fin_suspension', '<=', now())
            ->whereHas('user', fn ($q) => $q->where('estado', GestorUserStatus::Activo))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, array{subsistema: string, exito: bool, mensaje: string, cuenta: ?UserSubsystemAccount}>
     */
    public function reactivarVencidas(): Collection
    {
        return $this->cuentasVencidas()
            ->map(fn (UserSubsystemAccount $cuenta) => $this->reactivarCuenta($cuenta))
            ->values();
    }

    /**
     * Reactiva una cuenta en su subsistema y, si el subsistema lo confirma,
     * la deja activa en la base de datos.
     *
     * Es pública para que la llame quien registra la operación en el
     * historial. No lanza excepciones: los errores vuelven como
     * `exito => false` con su mensaje.
     *
     * @return array{subsistema: string, exito: bool, mensaje: string, cuenta: ?UserSubsystemAccount}
     */
    public function reactivarCuenta(UserSubsystemAccount $cuenta): array
    {
        /** @var Subsystem|null $subsistema */
        $subsistema = $cuenta->subsystem;

        if (! $subsistema) {
            return [
                'subsistema' => '(desconocido)',
                'exito' => false,
                'mensaje' => 'La cuenta no tiene subsistema asociado.',
                'cuenta' => $cuenta,
            ];
        }

        try {
            $servicio = $this->registry->resolve($subsistema->slug);
            $resultado = $servicio->reactivateUser($cuenta);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'subsistema' => $subsistema->slug,
                'exito' => false,
                'mensaje' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Error inesperado: '.$exception::class,
                'cuenta' => $cuenta,
            ];
        }

        if (! $resultado->success) {
            return [
                'subsistema' => $subsistema->slug,
                'exito' => false,
                'mensaje' => $resultado->mensaje ?? 'El subsistema no pudo reactivar la cuenta.',
                'cuenta' => $cuenta,
            ];
        }

        // Cuando el subsistema devuelve un id nuevo (Chatwoot recrea al agente
        // cuando ya no existe) se apunta antes de confirmar: la confirmación
        // se consulta sobre ese id.
        if (! blank($resultado->externalAccountId)) {
            $cuenta->external_account_id = $resultado->externalAccountId;
        }

        // Igual que en la suspensión: se confirma antes de escribir en la BD.
        try {
            $confirmado = $this->confirmaciones->confirmar($servicio, $cuenta, ['activo'], $resultado->estado)['confirmado'];
        } catch (Throwable $exception) {
            report($exception);

            $confirmado = false;
        }

        if (! $confirmado) {
            return [
                'subsistema' => $subsistema->slug,
                'exito' => false,
                'mensaje' => 'El subsistema no confirmó la reactivación de la cuenta.',
                'cuenta' => $cuenta,
            ];
        }

        $atributos = $cuenta->atributosAlReactivar();

        if (! blank($resultado->externalAccountId)) {
            $atributos['external_account_id'] = $resultado->externalAccountId;
        }

        // La configuración guardada de la cuenta (cajas de entrada y equipos) se
        // actualiza con lo que el subsistema devuelve al reactivar.
        if ($resultado->configuracionAdicional !== []) {
            $atributos['configuracion_adicional'] = $resultado->configuracionAdicional;
        }

        $cuenta->update($atributos);

        return [
            'subsistema' => $subsistema->slug,
            'exito' => true,
            // El mensaje del driver manda: dice si el agente se recreó o solo
            // se le devolvieron los accesos.
            'mensaje' => $resultado->mensaje ?? 'Cuenta reactivada.',
            'cuenta' => $cuenta->fresh() ?? $cuenta,
        ];
    }
}
