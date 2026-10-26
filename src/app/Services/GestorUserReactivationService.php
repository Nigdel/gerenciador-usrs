<?php

namespace App\Services;

use App\Enums\GestorUserStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Exceptions\ProvisioningException;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reactiva a los gestores cuya suspensión temporal ya venció.
 *
 * El criterio es `fin_suspension_gestor <= now()`: una suspensión sin fecha de
 * fin es indefinida y no se reactiva sola. Igual que la reactivación manual, la
 * operación se encola y es el cierre de sus cuentas el que devuelve al gestor a
 * 'activo'. Si alguna cuenta falla, el gestor sigue suspendido y la próxima
 * pasada del scheduler vuelve a intentarlo.
 */
class GestorUserReactivationService
{
    public function __construct(
        private readonly ProvisioningOperationService $operaciones,
        private readonly AuditService $audit,
    ) {}

    /**
     * Gestores suspendidos cuya fecha de fin ya pasó.
     *
     * Se expone separada de la ejecución para que el comando pueda listarlos
     * con --dry-run sin tocar nada.
     *
     * @return Collection<int, GestorUser>
     */
    public function gestoresVencidos(): Collection
    {
        return GestorUser::query()
            ->where('estado', GestorUserStatus::Suspendido)
            ->whereNotNull('fin_suspension_gestor')
            ->where('fin_suspension_gestor', '<=', now())
            ->orderBy('fin_suspension_gestor')
            ->orderBy('id')
            ->get();
    }

    /**
     * Reactiva a todos los gestores vencidos, aislando el fallo de cada uno.
     *
     * Un error en un gestor (por ejemplo, porque tiene otra operación en
     * curso) no debe impedir que se reactiven los demás; el scheduler vuelve a
     * intentarlo en la próxima pasada.
     *
     * @return Collection<int, array{gestor: GestorUser, operacion: ?ProvisioningOperation, exito: bool, mensaje: string}>
     */
    public function reactivarVencidos(): Collection
    {
        return $this->gestoresVencidos()
            ->map(fn (GestorUser $gestorUser) => $this->reactivarConResultado($gestorUser))
            ->values();
    }

    /**
     * Encola la reactivación del gestor y devuelve la operación creada.
     *
     * No comprueba permisos: el llamador (controlador o scheduler) es quien
     * decide si esa reactivación está autorizada. El controlador puede
     * adelantar la reactivación antes de la fecha de fin; el scheduler no,
     * porque su trabajo es justamente esperar a que venza.
     *
     * @throws ProvisioningException si el gestor ya no está suspendido o, con $soloSiVencida, si su fecha de fin todavía no venció.
     */
    public function reactivar(GestorUser $gestorUser, bool $soloSiVencida = false): ProvisioningOperation
    {
        $operacion = DB::transaction(function () use ($gestorUser, $soloSiVencida) {
            $usuario = GestorUser::query()
                ->whereKey($gestorUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $usuario->estaSuspendido()) {
                throw new ProvisioningException('El gestor no está suspendido.');
            }

            // La fecha se vuelve a comprobar dentro del lock cuando el que
            // reactiva es el scheduler: entre la consulta del comando y esta
            // transacción alguien pudo ampliar la suspensión, y reactivar antes
            // de tiempo contradiría lo que pidió el operador.
            if ($soloSiVencida && ($usuario->fin_suspension_gestor === null || $usuario->fin_suspension_gestor->isFuture())) {
                throw new ProvisioningException('La suspensión del gestor todavía no ha vencido.');
            }

            $cuentas = $usuario->cuentasSuspendidasPorGestor()
                ->with('subsystem')
                ->where('user_subsystem_accounts.estado', SubsystemAccountStatus::Deshabilitado)
                ->orderBy('user_subsystem_accounts.id')
                ->get();

            return $this->operaciones->describir(
                OperationType::ReactivacionGestor,
                $usuario,
                $cuentas,
            );
        });

        $this->operaciones->despachar($operacion);

        $this->audit->log(AuditService::USUARIO_REACTIVADO_GESTOR, [
            'gestor_user_id' => $gestorUser->id,
            'nombre_completo' => $gestorUser->nombre_completo,
            'operacion_id' => $operacion->uuid,
            'cuentas' => $operacion->cuentas()->pluck('subsistema')->all(),
        ]);

        return $operacion;
    }

    /**
     * @return array{gestor: GestorUser, operacion: ?ProvisioningOperation, exito: bool, mensaje: string}
     */
    private function reactivarConResultado(GestorUser $gestorUser): array
    {
        try {
            return [
                'gestor' => $gestorUser,
                'operacion' => $this->reactivar($gestorUser, soloSiVencida: true),
                'exito' => true,
                'mensaje' => 'Reactivación encolada.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'gestor' => $gestorUser,
                'operacion' => null,
                'exito' => false,
                'mensaje' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Error inesperado: '.$exception::class,
            ];
        }
    }
}
