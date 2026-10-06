<?php

namespace Tests;

use App\Enums\OperationType;
use App\Jobs\ProcessOperationAccount;
use App\Models\ProvisioningOperation;
use App\Models\ProvisioningOperationAccount;
use App\Services\ProvisioningOperationService;
use App\Services\UserDataSyncService;
use App\Services\UserOffboardingService;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ejecuta los jobs de una operación, cuenta a cuenta, y devuelve lo que
     * cada una acabó diciendo.
     *
     * Desde la Fase 3.2 el trabajo real sale de la petición y lo hace un job
     * por cuenta. Los tests que comprueban el efecto por cuenta —que un
     * subsistema no se toca si no confirma, que la baja no borre, que el
     * histórico se escriba— no necesitan comprobar el encolado para nada, así
     * que invocan el job directamente con la misma cadena de servicios que la
     * que inyectaría el contenedor. El encolado en sí, y que la petición no
     * salga a la red, lo comprueba QueuedSubsystemOperationTest con
     * Queue::fake().
     *
     * Los jobs se ejecutan uno a uno y no con despachar() a propósito: correrlos
     * en paralelo dentro del test haría que 'exitos' y 'errores' de la
     * operación dependieran del orden, y hay tests que afirman justo eso.
     *
     * @return array<int, array{subsistema: ?string, exito: bool, mensaje: ?string, intentos: int}>
     */
    protected function procesarOperacion(ProvisioningOperation $operacion): array
    {
        $ids = $operacion->cuentas()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            (new ProcessOperationAccount($id))->handle(
                app(ProvisioningOperationService::class),
                app(UserProvisioningService::class),
                app(UserSuspensionService::class),
                app(UserOffboardingService::class),
                app(UserDataSyncService::class),
            );
        }

        return ProvisioningOperationAccount::query()
            ->where('provisioning_operation_id', $operacion->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ProvisioningOperationAccount $fila) => [
                'subsistema' => $fila->subsistema,
                'exito' => $fila->estado->value === 'ok',
                'mensaje' => $fila->mensaje,
                'intentos' => $fila->intentos,
            ])
            ->all();
    }

    /**
     * Suspende a un usuario como lo haría el controlador, ejecutando después
     * los jobs, y devuelve lo que acabó diciendo cada cuenta.
     *
     * Mantiene la firma del antiguo UserSuspensionService::suspender() a
     * propósito: los tests que ya existían se centran en el efecto sobre la
     * cuenta y en el histórico, no en la forma de la llamada, y obligarlos a
     * reescribirse solo por el paso a la cola no aportaría nada.
     *
     * @param  array{usuario: string, motivo_suspension?: ?string, inicio_suspension?: ?string, fin_suspension?: ?string, subsistemas?: ?array}  $payload
     * @return array<int, array{subsistema: ?string, exito: bool, mensaje: ?string, intentos: int}>
     */
    protected function suspender(array $payload): array
    {
        $servicio = app(UserSuspensionService::class);

        $gestorUser = $servicio->localizarUsuario($payload);

        $datos = $servicio->datosDeSuspension([
            'motivo_suspension' => $payload['motivo_suspension'] ?? null,
            'inicio_suspension' => $payload['inicio_suspension'] ?? null,
            'fin_suspension' => $payload['fin_suspension'] ?? null,
        ]);

        $cuentas = $servicio->cuentasASuspender($gestorUser, $payload['subsistemas'] ?? null);

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Suspension,
            $gestorUser,
            $cuentas,
            ['motivo_suspension' => $datos['motivo_suspension']],
            array_fill_keys($cuentas->pluck('id')->all(), $datos),
        );

        return $this->procesarOperacion($operacion);
    }
}
