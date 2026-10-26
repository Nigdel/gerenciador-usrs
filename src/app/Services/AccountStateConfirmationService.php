<?php

namespace App\Services;

use App\Models\UserSubsystemAccount;

/**
 * Confirma contra el subsistema que un cambio de estado se aplicó de verdad
 * (el patrón confirm-before-persist que comparten la baja, la suspensión y la
 * reactivación).
 *
 * La confirmación normal es consultar el estado remoto de la cuenta. Hay
 * drivers que no pueden verificarlo —Chatwoot no tiene estados de suspensión o
 * deshabilitado, desactivar es quitarle el acceso al agente— y en ese caso se
 * confirma con el estado que devolvió la propia operación, que es el único
 * dato que hay.
 *
 * La consulta se repite antes de dar la operación por fallida porque no todos
 * los subsistemas reflejan el cambio en la lectura siguiente: Microsoft Graph
 * es eventualmente consistente y un GET inmediatamente después del PATCH puede
 * devolver todavía el valor anterior. Con una sola lectura eso se reportaba
 * como "el subsistema no confirmó" con la cuenta ya deshabilitada en Entra ID.
 *
 * Cuántas veces y cuánto esperar lo decide el driver
 * (intentosConfirmacionEstado() / retardoConfirmacionEstadoMs()) y se puede
 * ajustar por subsistema con state_confirmation_attempts y
 * state_confirmation_delay_ms en api_config, que tienen prioridad.
 */
class AccountStateConfirmationService
{
    /**
     * Tope de reintentos y de espera entre ellos: un api_config absurdo no
     * puede dejar colgado al job que espera la confirmación.
     */
    private const MAX_INTENTOS = 10;

    private const MAX_RETARDO_MS = 5000;

    /**
     * @param  array<int, string>  $estadosAceptados
     * @return array{confirmado: bool, detalle: ?string}
     */
    public function confirmar(
        mixed $servicio,
        UserSubsystemAccount $cuenta,
        array $estadosAceptados,
        ?string $estadoOperacion = null,
    ): array {
        if (! $this->verificaEstadoRemoto($servicio)) {
            return [
                'confirmado' => $estadoOperacion !== null
                    && in_array($estadoOperacion, $estadosAceptados, true),
                'detalle' => null,
            ];
        }

        $intentos = $this->intentos($servicio, $cuenta);
        $retardoMs = $this->retardoMs($servicio, $cuenta);
        $detalle = null;

        for ($intento = 1; $intento <= $intentos; $intento++) {
            $estadoRemoto = $servicio->getUserStatus($cuenta);

            if ($estadoRemoto->success && in_array($estadoRemoto->estado, $estadosAceptados, true)) {
                return ['confirmado' => true, 'detalle' => null];
            }

            // Se guarda el último motivo para que quien informe del fallo pueda
            // decir qué vio en remoto y no solo que no confirmó.
            $detalle = $estadoRemoto->success
                ? 'estado remoto: '.($estadoRemoto->estado ?? 'desconocido')
                : $estadoRemoto->mensaje;

            if ($intento < $intentos && $retardoMs > 0) {
                usleep($retardoMs * 1000);
            }
        }

        return ['confirmado' => false, 'detalle' => $detalle];
    }

    private function intentos(mixed $servicio, UserSubsystemAccount $cuenta): int
    {
        $configurado = $cuenta->subsystem?->api_config['state_confirmation_attempts'] ?? null;

        $intentos = $configurado !== null && $configurado !== ''
            ? (int) $configurado
            : $this->delDriver($servicio, 'intentosConfirmacionEstado', 1);

        return max(1, min($intentos, self::MAX_INTENTOS));
    }

    private function retardoMs(mixed $servicio, UserSubsystemAccount $cuenta): int
    {
        $configurado = $cuenta->subsystem?->api_config['state_confirmation_delay_ms'] ?? null;

        $retardo = $configurado !== null && $configurado !== ''
            ? (int) $configurado
            : $this->delDriver($servicio, 'retardoConfirmacionEstadoMs', 0);

        return max(0, min($retardo, self::MAX_RETARDO_MS));
    }

    /**
     * Los drivers que no heredan de BaseSubsystemService y no declaran estas
     * esperas se quedan con una sola lectura, como antes.
     */
    private function delDriver(mixed $servicio, string $metodo, int $porDefecto): int
    {
        if (! is_callable([$servicio, $metodo])) {
            return $porDefecto;
        }

        return (int) call_user_func([$servicio, $metodo]);
    }

    private function verificaEstadoRemoto(mixed $servicio): bool
    {
        return ! (is_callable([$servicio, 'verificaEstadoRemoto'])
            && ! call_user_func([$servicio, 'verificaEstadoRemoto']));
    }
}
