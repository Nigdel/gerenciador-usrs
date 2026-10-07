<?php

namespace App\Services;

use App\Enums\GestorUserStatus;
use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Jobs\ProcessOperationAccount;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\ProvisioningOperationAccount;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Support\ActorContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ciclo de vida de una operación que sale a los subsistemas (Fase 3.2).
 *
 * Antes de la cola, el resultado de una acción vivía solo en el flash de la
 * sesión: si el operador recargaba se perdía, y si la petición moría a mitad
 * no quedaba rastro de lo que se había tocado. Aquí la operación se persiste
 * antes de encolar y se va actualizando cuenta a cuenta.
 *
 * El reparto de responsabilidades es el siguiente: el controlador **prepara**
 * (valida y llama a describir() + despachar()), el job **ejecuta** una cuenta y
 * llama a registrarResultado(), y cerrar() es el único sitio que sabe cuándo
 * se acabó todo y puede, por tanto, escribir el estado 'baja' del usuario —que
 * depende de que TODAS las cuentas salieran bien y que un job por cuenta no
 * puede decidir.
 */
class ProvisioningOperationService
{
    /**
     * Cola propia y no la de por defecto: es trabajo lento, con llamadas
     * salientes que pueden tardar, y no debe competir con nada urgentísimo que
     * se_enqueue en 'default' más adelante.
     */
    public const COLA = 'subsistemas';

    /**
     * Crea la operación y una fila 'pendiente' por cada unidad de trabajo.
     *
     * No despacha nada: separar ambas cosas permite montar una operación entera
     * en una transacción y encolar solo cuando está confirmada, y deja este
     * servicio testeable sin tocar la cola.
     *
     * @param  Collection<int, Subsystem|UserSubsystemAccount>  $trabajo  subsistemas en el alta, cuentas en el resto
     *
     * Acepta cualquier Collection —las de Eloquent y las de Support sirven igual— porque
     * lo único que se usa de ella es `isEmpty()`, `count()` y recorrerla.
     * @param  array<string, mixed>  $payload  datos de la operación (motivo de baja, motivo y fechas de suspensión...)
     * @param  array<int, array<string, mixed>>  $payloadPorCuenta  lo que cada fila necesita guardar
     */
    public function describir(
        OperationType $tipo,
        ?GestorUser $usuario,
        Collection $trabajo,
        array $payload = [],
        array $payloadPorCuenta = [],
    ): ProvisioningOperation {
        $actor = ActorContext::actual();

        return DB::transaction(function () use ($tipo, $usuario, $trabajo, $payload, $payloadPorCuenta, $actor) {
            $operacion = ProvisioningOperation::create([
                'gestor_user_id' => $usuario?->id,
                'tipo' => $tipo,
                'estado' => $trabajo->isEmpty() ? OperationStatus::Completada : OperationStatus::Pendiente,
                'payload' => $payload ?: null,
                'actor_id' => $actor?->id,
                // Congelado: si el operador se borra, la operación no debe
                // quedarse sin autor legible.
                'actor_nombre' => $actor?->name,
                'origen' => ActorContext::origen(),
                'pendientes' => $trabajo->count(),
            ]);

            $operacion->terminada_at = $operacion->estado === OperationStatus::Completada ? now() : null;
            $operacion->save();

            foreach ($trabajo as $unidad) {
                ProvisioningOperationAccount::create([
                    'provisioning_operation_id' => $operacion->id,
                    'user_subsystem_account_id' => $unidad instanceof UserSubsystemAccount ? $unidad->id : null,
                    'subsystem_id' => $unidad instanceof UserSubsystemAccount ? $unidad->subsystem_id : $unidad->id,
                    'subsistema' => $unidad instanceof UserSubsystemAccount ? $unidad->subsystem?->slug : $unidad->slug,
                    'estado' => OperationAccountStatus::Pendiente,
                    'payload' => $payloadPorCuenta[$unidad->getKey()] ?? null,
                ]);
            }

            return $operacion;
        });
    }

    /**
     * Encola un job por cada cuenta pendiente.
     *
     * Los ids se releen de la base y no de la colección recibida porque
     * despachar() puede llamarse más de una vez sobre la misma operación (por
     * ejemplo, para reintentar) y tiene que encolar solo lo que falte.
     */
    public function despachar(ProvisioningOperation $operacion): void
    {
        // Antes de despachar, no después: con QUEUE_CONNECTION=sync los jobs
        // corren dentro de este mismo bucle y cerrar() deja la operación en
        // Completada/Fallida. Marcarla EnCurso al final pisaría ese resultado
        // y la operación se quedaría 'en curso' para siempre.
        $operacion->forceFill(['estado' => OperationStatus::EnCurso])->save();

        $operacion->cuentas()
            ->where('estado', OperationAccountStatus::Pendiente)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $filaId) => ProcessOperationAccount::dispatch($filaId)->onQueue(self::COLA));
    }

    /**
     * Escribe el resultado de una cuenta y cierra la operación si ya no queda
     * ninguna pendiente.
     *
     * @param  array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}  $resultado
     */
    public function registrarResultado(ProvisioningOperationAccount $fila, array $resultado): void
    {
        $fila->update([
            'estado' => $resultado['exito'] ? OperationAccountStatus::Ok : OperationAccountStatus::Error,
            'mensaje' => $resultado['mensaje'],
            // En el alta la cuenta la crea el propio job, así que el id llega
            // con el resultado y hay que apuntarlo: sin él, el botón de
            // reintentar del 3.3 no sabría sobre qué cuenta trabajar.
            'user_subsystem_account_id' => $fila->user_subsystem_account_id
                ?? $resultado['cuenta']?->id,
        ]);

        $this->cerrar($fila->operacion()->first());
    }

    /**
     * Cierra la operación si ya no queda ninguna cuenta pendiente.
     *
     * Recibe la operación y no un id porque necesita releer las filas para
     * contar: los contadores desnormalizados de la cabecera se recalculan aquí
     * para que no puedan quedar desfasados respecto a las filas.
     */
    public function cerrar(ProvisioningOperation $operacion): void
    {
        $pendientes = $operacion->cuentas()
            ->where('estado', OperationAccountStatus::Pendiente)
            ->count();

        if ($pendientes > 0) {
            return;
        }

        $exitos = $operacion->cuentas()->where('estado', OperationAccountStatus::Ok)->count();
        $errores = $operacion->cuentas()->where('estado', OperationAccountStatus::Error)->count();

        $operacion->forceFill([
            'estado' => $errores > 0 ? OperationStatus::Fallida : OperationStatus::Completada,
            'pendientes' => 0,
            'exitos' => $exitos,
            'errores' => $errores,
            'terminada_at' => now(),
        ])->save();

        $this->aplicarEstadoDelUsuario($operacion, $errores);

        // Una operación completada no tiene a nadie a quien darle de alta, así
        // que su contraseña general ya no hace falta: se va en el mismo
        // momento en que se cierra y no espera al prune. Las fallidas la
        // conservan, porque un reintento aún la necesita.
        if ($errores === 0) {
            $operacion->podarSecretos();
        }
    }

    /**
     * Marca al usuario de baja (o lo devuelve a activo) cuando la operación se
     * cerró sin un solo error.
     *
     * Se mantiene aquí y no en UserOffboardingService a propósito: depended de
     * que TODAS las cuentas hayan salido bien, y por eso mismo no puede vivir
     * en un job que solo conoce la suya. Marcarlo antes dejaría al usuario dado
     * de baja con accesos vivos, que es justo lo que la Fase 2.6 evitaba.
     */
    private function aplicarEstadoDelUsuario(ProvisioningOperation $operacion, int $errores): void
    {
        if ($errores > 0) {
            return;
        }

        if (! in_array($operacion->tipo, OperationType::queCambianElEstadoDelUsuario(), true)) {
            return;
        }

        $usuario = $operacion->usuario;

        if ($usuario === null) {
            return;
        }

        if ($operacion->tipo === OperationType::Baja) {
            $usuario->update([
                'estado' => GestorUserStatus::Baja,
                'baja_at' => now(),
                'motivo_baja' => $operacion->payload['motivo_baja'] ?? null,
            ]);

            return;
        }

        $usuario->update([
            'estado' => GestorUserStatus::Activo,
            'baja_at' => null,
            'motivo_baja' => null,
        ]);
    }

    /**
     * Deja una cuenta en error tras agotar los reintentos.
     *
     * Sin esto, una operación con un job que falla tres veces se quedaría
     * eternamente 'en_curso' y el operador vería un trabajo que no termina sin
     * explicación.
     */
    public function registrarFallo(ProvisioningOperationAccount $fila, string $mensaje): void
    {
        $fila->update([
            'estado' => OperationAccountStatus::Error,
            'mensaje' => $mensaje,
        ]);

        $this->cerrar($fila->operacion()->first());
    }

    /**
     * Forma JSON que consumen la API y el endpoint de polling del panel.
     *
     * @return array{uuid: string, tipo: string, estado: string, terminado: bool, resumen: string, cuentas: array<int, array>}
     */
    public function serializar(ProvisioningOperation $operacion): array
    {
        $operacion->loadMissing('cuentas');

        return [
            'uuid' => $operacion->uuid,
            'tipo' => $operacion->tipo->value,
            'estado' => $operacion->estado->value,
            'terminado' => $operacion->estaTerminada(),
            'resumen' => $operacion->resumen(),
            'cuentas' => $operacion->cuentas->map(fn (ProvisioningOperationAccount $cuenta) => [
                'subsistema' => $cuenta->subsistema,
                'estado' => $cuenta->estado->value,
                'mensaje' => $cuenta->mensaje,
                'intentos' => $cuenta->intentos,
            ])->all(),
        ];
    }

    /**
     * Un caso de alta en Adagio o similar puede lanzar RuntimeException si
     * falta configuración; se registra y se convierte en fila 'error' sin que
     * eso tumbe la operación entera.
     */
    public function mensajeDeThrowable(Throwable $exception): string
    {
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Error inesperado: '.$exception::class;
    }
}
