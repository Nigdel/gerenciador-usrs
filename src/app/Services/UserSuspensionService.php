<?php

namespace App\Services;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Carbon;

/**
 * Orquesta la suspensión de un usuario en uno, varios, o todos los
 * subsistemas donde tenga cuenta. Si el usuario ya estaba suspendido en un
 * subsistema, simplemente se actualizan inicio/fin/motivo de la suspensión.
 */
class UserSuspensionService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {
    }

    /**
     * @param  array  $payload  Formato esperado:
     *   [
     *     'cpf' => '...', // o 'usuario' => '...' para identificar al GestorUser
     *     'subsistemas' => ['glpi', 'entraid'], // opcional: si falta, se suspende en todos donde tenga cuenta
     *     'motivo_suspension' => '...',
     *     'inicio_suspension' => '2026-09-21', // opcional, default: ahora
     *     'fin_suspension' => '2026-10-05',     // opcional
     *   ]
     *
     * @return array<int, array>
     */
    public function suspender(array $payload): array
    {
        $gestorUser = $this->localizarUsuario($payload);

        $cuentas = $this->resolverCuentas($gestorUser, $payload['subsistemas'] ?? null);

        $datosSuspension = [
            'motivo_suspension' => $payload['motivo_suspension'] ?? null,
            'inicio_suspension' => isset($payload['inicio_suspension'])
                ? Carbon::parse($payload['inicio_suspension'])
                : now(),
            'fin_suspension' => isset($payload['fin_suspension'])
                ? Carbon::parse($payload['fin_suspension'])
                : null,
        ];

        $resultados = [];
        foreach ($cuentas as $account) {
            $resultados[] = $this->suspenderCuenta($account, $datosSuspension);
        }

        return $resultados;
    }

    private function localizarUsuario(array $payload): GestorUser
    {
        if (! empty($payload['cpf'])) {
            return GestorUser::where('cpf', $payload['cpf'])->firstOrFail();
        }

        if (! empty($payload['usuario'])) {
            return GestorUser::where('usuario', $payload['usuario'])->firstOrFail();
        }

        throw new \InvalidArgumentException('Se requiere "cpf" o "usuario" para identificar al usuario a suspender');
    }

    /**
     * @return \Illuminate\Support\Collection<int, UserSubsystemAccount>
     */
    private function resolverCuentas(GestorUser $gestorUser, ?array $slugs)
    {
        $query = $gestorUser->subsystemAccounts()->with('subsystem');

        if (! empty($slugs)) {
            $query->whereHas('subsystem', fn ($q) => $q->whereIn('slug', $slugs));
        }

        return $query->get();
    }

    private function suspenderCuenta(UserSubsystemAccount $account, array $datosSuspension): array
    {
        /** @var Subsystem $subsystem */
        $subsystem = $account->subsystem;

        $servicio = $this->registry->resolve($subsystem->slug);
        $resultado = $servicio->suspendUser($account, $datosSuspension);
        $confirmado = false;

        if ($resultado->success) {
            $estadoRemoto = $servicio->getUserStatus($account);
            $confirmado = $estadoRemoto->success
                && in_array($estadoRemoto->estado, ['suspendido', 'deshabilitado'], true);

            if ($confirmado) {
                $account->update([
                    'estado' => 'suspendido',
                    'inicio_suspension' => $datosSuspension['inicio_suspension'],
                    'fin_suspension' => $datosSuspension['fin_suspension'],
                    'motivo_suspension' => $datosSuspension['motivo_suspension'],
                ]);
            }
        }

        return [
            'subsistema' => $subsystem->slug,
            'exito' => $resultado->success && $confirmado,
            'mensaje' => $resultado->success && ! $confirmado
                ? 'El subsistema no confirmó la suspensión de la cuenta.'
                : $resultado->mensaje,
            'cuenta' => $account->fresh(),
        ];
    }
}
