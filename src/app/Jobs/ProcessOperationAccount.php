<?php

namespace App\Jobs;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationType;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\ProvisioningOperationAccount;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use App\Services\UserDataSyncService;
use App\Services\UserOffboardingService;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Ejecuta una cuenta de una operación contra su subsistema (Fase 3.2).
 *
 * Un solo job para los cinco tipos de operación, en vez de uno por tipo: los
 * cinco métodos hacen exactamente lo mismo (llamar al driver, devolver un
 * resultado con la misma forma) y separarlos obligaría a duplicar cinco veces
 * el backoff, el marcado de reintentos agotados y el cierre de la operación,
 * que es justo lo que cambiaría en la idempotencia del 3.4.
 *
 * Solo lleva el id de la fila de trabajo, no el modelo entero: la fila cambia
 * de estado mientras el job espera en la cola, y un modelo serializado
 * escribiría encima cambios que otro proceso ya hizo.
 */
class ProcessOperationAccount implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tres intentos, no uno: un fallo de red a la primera es lo normal cuando
     * se sale a un LDAP o a una API interna, y un reintento lo resuelve sin
     * obligar al operador a pulsar nada.
     */
    public int $tries = 3;

    /** Margen sobre el timeout del driver, para el desenchufe de la llamada. */
    public int $timeout = 120;

    /**
     * Espera creciente entre reintentos: 30 s, 2 min, 10 min.
     *
     * El backoff se declara como método y no como propiedad porque Laravel
     * resuelve este valor con Arr::get sobre la propiedad o el método; el
     * método tiene prioridad y evita que la forma de uno pise a la del otro.
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(public readonly int $filaId) {}

    public function handle(
        ProvisioningOperationService $operaciones,
        UserProvisioningService $provisioning,
        UserSuspensionService $suspension,
        UserOffboardingService $offboarding,
        UserDataSyncService $sync,
    ): void {
        $fila = ProvisioningOperationAccount::query()->with('operacion')->find($this->filaId);

        if ($fila === null) {
            // La operación se borró (cascade) mientras este job esperaba: no
            // hay nada que hacer y no se avisa de un fallo que no ocurrió.
            return;
        }

        if ($fila->estado !== OperationAccountStatus::Pendiente) {
            // El job ya se ejecutó (o se reintentó a mano sobre una cuenta ya
            // resuelta). Volver a tocar el subsistema aquí sería una segunda
            // escritura de la misma operación; la guarda lo evita y deja la
            // idempotencia formal para el 3.4.
            return;
        }

        $operacion = $fila->operacion;

        // El usuario puede haberse borrado después de encolar la operación.
        // Entonces no hay a quién dar de alta ni a quién colgarle el estado.
        $usuario = $operacion?->gestor_user_id
            ? GestorUser::query()->find($operacion->gestor_user_id)
            : null;

        if ($usuario === null) {
            $operaciones->registrarFallo($fila, 'El usuario de esta operación ya no existe.');

            return;
        }

        $fila->increment('intentos');

        $resultado = match ($operacion->tipo) {
            OperationType::Alta => $this->darDeAlta($fila, $usuario, $operacion, $provisioning),
            OperationType::Suspension => $this->suspender($fila, $suspension),
            OperationType::Baja => $this->sobreLaCuenta(
                $fila,
                fn (UserSubsystemAccount $cuenta) => $offboarding->darDeBajaCuenta($cuenta),
            ),
            OperationType::Reactivacion => $this->sobreLaCuenta(
                $fila,
                fn (UserSubsystemAccount $cuenta) => $offboarding->reactivarCuenta($cuenta),
            ),
            OperationType::Sincronizacion => $this->sobreLaCuenta(
                $fila,
                fn (UserSubsystemAccount $cuenta) => $sync->sincronizarCuenta($cuenta, $usuario),
            ),
        };

        $operaciones->registrarResultado($fila, $resultado);
    }

    /**
     * Ejecuta la acción sobre la cuenta de la fila.
     *
     * La cuenta puede haberse borrado entre que se encoló la operación y que
     * se ejecutó este job (el propio operador puede hacerlo desde la ficha).
     * Eso se reporta como error de la cuenta y no como excepción, para que el
     * resto de la operación siga adelante.
     *
     * @param  callable(UserSubsystemAccount): array  $accion
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    private function sobreLaCuenta(ProvisioningOperationAccount $fila, callable $accion): array
    {
        $cuenta = $fila->cuenta;

        if (! $cuenta instanceof UserSubsystemAccount) {
            return [
                'subsistema' => $fila->subsistema,
                'exito' => false,
                'mensaje' => 'La cuenta ya no existe.',
                'cuenta' => null,
            ];
        }

        return $accion($cuenta);
    }

    /**
     * Se llama cuando se agotan los tres intentos.
     *
     * Sin esto la operación se quedaría 'en_curso' para siempre: nadie más va
     * a escribir esa fila, y el operador vería un trabajo que no termina ni
     * explica por qué.
     */
    public function failed(?Throwable $exception): void
    {
        $fila = ProvisioningOperationAccount::query()->find($this->filaId);

        if ($fila === null || $fila->estado !== OperationAccountStatus::Pendiente) {
            return;
        }

        app(ProvisioningOperationService::class)->registrarFallo(
            $fila,
            $exception === null
                ? 'El trabajo agotó sus reintentos.'
                : $exception->getMessage(),
        );
    }

    /**
     * El alta es el único caso en que la cuenta todavía no existe: la fila de
     * trabajo apunta al subsistema, no a una cuenta.
     */
    private function darDeAlta(
        ProvisioningOperationAccount $fila,
        GestorUser $usuario,
        ProvisioningOperation $operacion,
        UserProvisioningService $provisioning,
    ): array {
        $subsistema = Subsystem::query()->find($fila->subsystem_id);

        if ($subsistema === null) {
            throw new RuntimeException('El subsistema '.$fila->subsistema.' ya no existe.');
        }

        return $provisioning->crearEnSubsistema(
            $usuario,
            $subsistema,
            $operacion->payload ?? [],
            $operacion->payload['subsystem_config'] ?? [],
        );
    }

    private function suspender(
        ProvisioningOperationAccount $fila,
        UserSuspensionService $suspension,
    ): array {
        $cuenta = $fila->cuenta;

        if (! $cuenta instanceof UserSubsystemAccount) {
            return [
                'subsistema' => $fila->subsistema,
                'exito' => false,
                'mensaje' => 'La cuenta que había que suspender ya no existe.',
                'cuenta' => null,
            ];
        }

        // El payload viene del JSON de la base: las fechas llegan como texto ISO
        // y hay que volver a convertirlas a Carbon, que es lo que espera
        // suspenderCuenta() para decidir si la suspensión se agenda o se aplica.
        return $suspension->suspenderCuenta($cuenta, [
            'motivo_suspension' => $fila->payload['motivo_suspension'] ?? null,
            'inicio_suspension' => isset($fila->payload['inicio_suspension'])
                ? Carbon::parse($fila->payload['inicio_suspension'])
                : null,
            'fin_suspension' => isset($fila->payload['fin_suspension'])
                ? Carbon::parse($fila->payload['fin_suspension'])
                : null,
        ]);
    }
}