<?php

namespace App\Services;

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
 * Igual que en UserSuspensionService, la escritura en la BD sólo ocurre si el
 * subsistema confirma el nuevo estado: un fallo remoto no debe dejar la cuenta
 * marcada como activa.
 */
class AccountReactivationService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
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
            ->with('subsystem')
            ->where('estado', 'suspendido')
            ->whereNotNull('fin_suspension')
            ->where('fin_suspension', '<=', now())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, array{subsistema: string, exito: bool, mensaje: string}>
     */
    public function reactivarVencidas(): Collection
    {
        return $this->cuentasVencidas()
            ->map(fn (UserSubsystemAccount $cuenta) => $this->reactivarCuenta($cuenta))
            ->values();
    }

    /**
     * @return array{subsistema: string, exito: bool, mensaje: string}
     */
    private function reactivarCuenta(UserSubsystemAccount $cuenta): array
    {
        /** @var Subsystem $subsistema */
        $subsistema = $cuenta->subsystem;

        if (! $subsistema) {
            return [
                'subsistema' => '(desconocido)',
                'exito' => false,
                'mensaje' => 'La cuenta no tiene subsistema asociado.',
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
                'mensaje' => $exception->getMessage(),
            ];
        }

        if (! $resultado->success) {
            return [
                'subsistema' => $subsistema->slug,
                'exito' => false,
                'mensaje' => $resultado->mensaje ?? 'El subsistema no pudo reactivar la cuenta.',
            ];
        }

        // Cuando el subsistema devuelve un id nuevo (Chatwoot recrea al agente
        // cuando ya no existe) se apunta antes de confirmar: la confirmación
        // se consulta sobre ese id.
        if (! blank($resultado->externalAccountId)) {
            $cuenta->external_account_id = $resultado->externalAccountId;
        }

        // Igual que en la suspensión: se confirma antes de escribir en la BD.
        if (! $this->confirmar($servicio, $cuenta, $resultado->estado, ['activo'])) {
            return [
                'subsistema' => $subsistema->slug,
                'exito' => false,
                'mensaje' => 'El subsistema no confirmó la reactivación de la cuenta.',
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
            'mensaje' => 'Cuenta reactivada.',
        ];
    }

    /**
     * Confirma que la cuenta quedó activa en el subsistema.
     *
     * Se consulta el estado remoto, salvo que el driver no pueda verificarlo
     * (Chatwoot no tiene estados de suspensión o deshabilitado): en ese caso
     * se confirma con el estado que devuelve la propia operación.
     *
     * @param  array<int, string>  $estadosAceptados
     */
    private function confirmar(mixed $servicio, UserSubsystemAccount $cuenta, ?string $estadoOperacion, array $estadosAceptados): bool
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
}
