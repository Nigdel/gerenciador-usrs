<?php

namespace App\Services;

use App\Exceptions\ProvisioningException;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Orquesta la suspensión de un usuario en uno, varios, o todos los
 * subsistemas donde tenga cuenta. Si el usuario ya estaba suspendido en un
 * subsistema, simplemente se actualizan inicio/fin/motivo de la suspensión.
 *
 * Desde la Fase 3.2 ya **no itera cuentas**: eso lo hace un job por cuenta.
 * Aquí quedan cuentasASuspender(), que dice a quién hay que suspensionar, y
 * suspenderCuenta(), que el job llama una vez por cada una.
 */
class UserSuspensionService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly AccountStateConfirmationService $confirmaciones,
    ) {}

    /**
     * Localiza al usuario por CPF o por login, que es como llega desde la API.
     *
     * firstOrFail() a propósito: si no existe, la API responde 404, que es
     * más útil que un 500.
     *
     * @param  array{cpf?: ?string, usuario?: ?string}  $payload
     */
    public function localizarUsuario(array $payload): GestorUser
    {
        if (! empty($payload['cpf'])) {
            return GestorUser::query()->where('cpf', $payload['cpf'])->firstOrFail();
        }

        if (! empty($payload['usuario'])) {
            return GestorUser::query()->where('usuario', $payload['usuario'])->firstOrFail();
        }

        throw new ProvisioningException('Se requiere "cpf" o "usuario" para identificar al usuario a suspender');
    }

    /**
     * Cuentas sobre las que hay que actuar, ya filtradas por subsistema.
     *
     * @return Collection<int, UserSubsystemAccount>
     */
    public function cuentasASuspender(GestorUser $gestorUser, ?array $slugs = null): Collection
    {
        $query = $gestorUser->subsystemAccounts()->with('subsystem');

        if (! empty($slugs)) {
            $query->whereHas('subsystem', fn ($q) => $q->whereIn('slug', $slugs));
        }

        return $query->get();
    }

    /**
     * Datos de suspensión en la forma en que se guardan en el payload de la
     * operación y se leen en el job.
     *
     * Las fechas se guardan como texto ISO y no como Carbon porque el payload
     * se serializa a JSON para la base de datos: un Carbon dentro del json
     * saldría como un objeto y al releerlo no volvería a ser una fecha.
     *
     * @return array{motivo_suspension: ?string, inicio_suspension: string|null, fin_suspension: string|null}
     */
    public function datosDeSuspension(array $payload): array
    {
        return [
            'motivo_suspension' => $payload['motivo_suspension'] ?? null,
            'inicio_suspension' => isset($payload['inicio_suspension'])
                ? Carbon::parse($payload['inicio_suspension'])->toIso8601String()
                : null,
            'fin_suspension' => isset($payload['fin_suspension'])
                ? Carbon::parse($payload['fin_suspension'])->toIso8601String()
                : null,
        ];
    }

    /**
     * Suspende ahora las cuentas pendientes cuya fecha de inicio ya llegó.
     *
     * Es la otra mitad de la suspensión programada: cuando se agenda con
     * inicio_suspension en el futuro la cuenta queda en estado 'pendiente' y
     * sin tocar el subsistema; aquí se ejecuta.
     *
     * Sigue siendo síncrono a propósito: lo llama el scheduler desde el
     * contenedor `scheduler`, que ya está fuera de la petición. Encolar desde
     * aquí no aportaría nada y partiría la confirmación en dos sitios.
     *
     * suspenderCuenta() no lanza excepciones, así que una cuenta con error no
     * aborta el resto del lote.
     *
     * @return array<int, array>
     */
    public function suspenderPendientesVencidas(): array
    {
        return $this->cuentasPendientesVencidas()
            ->map(fn (UserSubsystemAccount $account) => $this->suspenderCuenta($account, [
                'motivo_suspension' => $account->motivo_suspension,
                'inicio_suspension' => $account->inicio_suspension,
                'fin_suspension' => $account->fin_suspension,
            ]))
            ->values()
            ->all();
    }

    /**
     * Cuentas con una suspensión agendada cuya fecha ya venció, sin aplicar.
     *
     * @return Collection<int, UserSubsystemAccount>
     */
    public function cuentasPendientesVencidas(): Collection
    {
        return UserSubsystemAccount::query()
            ->with('subsystem')
            ->where('estado', 'pendiente')
            ->whereNotNull('inicio_suspension')
            ->where('inicio_suspension', '<=', now())
            ->orderBy('id')
            ->get();
    }

    /**
     * Suspende una cuenta en su subsistema y, si el subsistema lo confirma,
     * apunta el estado, el motivo y las fechas en la cuenta.
     *
     * No lanza excepciones: cualquier error del subsistema (configuración
     * ausente, red caída...) vuelve como `exito => false` con su mensaje, para
     * que quien itera —el scheduler o el job— pueda registrarlo y seguir con
     * las demás cuentas.
     *
     * @return array{subsistema: string, exito: bool, mensaje: ?string, cuenta: UserSubsystemAccount}
     */
    public function suspenderCuenta(UserSubsystemAccount $account, array $datosSuspension): array
    {
        /** @var Subsystem|null $subsystem */
        $subsystem = $account->subsystem;

        if (! $subsystem) {
            return [
                'subsistema' => '(desconocido)',
                'exito' => false,
                'mensaje' => 'La cuenta no tiene subsistema asociado.',
                'cuenta' => $account,
            ];
        }

        // Sin fecha de inicio explícita se suspende ahora mismo. El job la
        // manda siempre como ISO o como null, de ahí el default.
        $inicio = isset($datosSuspension['inicio_suspension'])
            ? Carbon::parse($datosSuspension['inicio_suspension'])
            : now();

        $fin = isset($datosSuspension['fin_suspension'])
            ? Carbon::parse($datosSuspension['fin_suspension'])
            : null;

        $datosSuspension['motivo_suspension'] ??= null;
        $datosSuspension['inicio_suspension'] = $inicio;
        $datosSuspension['fin_suspension'] = $fin;

        // Suspensión programada (Fase 2.3): si la fecha de inicio aún no llega,
        // se agenda y no se toca el subsistema. La aplica
        // suspenderPendientesVencidas() cuando corresponda.
        if ($datosSuspension['inicio_suspension']->isFuture()) {
            $account->update([
                'estado' => 'pendiente',
                'inicio_suspension' => $datosSuspension['inicio_suspension'],
                'fin_suspension' => $datosSuspension['fin_suspension'],
                'motivo_suspension' => $datosSuspension['motivo_suspension'],
            ]);

            return [
                'subsistema' => $subsystem->slug,
                'exito' => true,
                'mensaje' => 'Suspensión programada para el '
                    .$datosSuspension['inicio_suspension']->format('d/m/Y').'.',
                'cuenta' => $account->fresh(),
            ];
        }

        try {
            $servicio = $this->registry->resolve($subsystem->slug);
            $resultado = $servicio->suspendUser($account, $datosSuspension);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'subsistema' => $subsystem->slug,
                'exito' => false,
                'mensaje' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Error inesperado: '.$exception::class,
                'cuenta' => $account->fresh() ?? $account,
            ];
        }

        $confirmado = false;
        $detalleVerificacion = null;

        if ($resultado->success) {
            try {
                $confirmacion = $this->confirmaciones->confirmar(
                    $servicio,
                    $account,
                    ['suspendido', 'deshabilitado'],
                    $resultado->estado,
                );

                $confirmado = $confirmacion['confirmado'];
                $detalleVerificacion = $confirmacion['detalle'];
            } catch (Throwable $exception) {
                report($exception);

                $confirmado = false;
                $detalleVerificacion = $exception->getMessage();
            }

            if ($confirmado) {
                $atributos = [
                    'estado' => 'suspendido',
                    'inicio_suspension' => $datosSuspension['inicio_suspension'],
                    'fin_suspension' => $datosSuspension['fin_suspension'],
                    'motivo_suspension' => $datosSuspension['motivo_suspension'],
                ];

                // La foto de accesos que el driver quitó (en Chatwoot, la
                // caja de entrada y los equipos) se guarda para que la
                // reactivación pueda devolverlos. Solo si viene con algo: un
                // resultado vacío no debe borrar lo que la cuenta ya tenía.
                if ($resultado->configuracionAdicional !== []) {
                    $atributos['configuracion_adicional'] = $resultado->configuracionAdicional;
                }

                $account->update($atributos);
            }
        }

        return [
            'subsistema' => $subsystem->slug,
            'exito' => $resultado->success && $confirmado,
            'mensaje' => $resultado->success && ! $confirmado
                ? 'El subsistema no confirmó la suspensión de la cuenta.'
                    .($detalleVerificacion ? " ({$detalleVerificacion})" : '')
                : $resultado->mensaje,
            'cuenta' => $account->fresh(),
        ];
    }
}
