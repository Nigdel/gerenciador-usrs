<?php

namespace App\Services;

use App\Enums\GestorUserStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Exceptions\ProvisioningException;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GestorUserSuspensionService
{
    public function __construct(
        private readonly ProvisioningOperationService $operations,
        private readonly AuditService $audit,
    ) {}

    public function suspender(
        GestorUser $gestorUser,
        string $motivo,
        ?string $inicio = null,
        ?string $fin = null,
    ): ProvisioningOperation {
        $inicioSuspension = $inicio !== null ? Carbon::parse($inicio) : now();
        $finSuspension = $fin !== null ? Carbon::parse($fin) : null;
        $programada = $inicioSuspension->isFuture();

        $operacion = DB::transaction(function () use ($gestorUser, $motivo, $inicioSuspension, $finSuspension, $programada) {
            $usuario = GestorUser::query()
                ->whereKey($gestorUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($usuario->estado !== GestorUserStatus::Activo) {
                throw new ProvisioningException('Solo se puede suspender un gestor activo.');
            }

            if ($usuario->cuentasSuspendidasPorGestor()->exists()) {
                throw new ProvisioningException('Hay una suspensión anterior incompleta. Reintenta sus cuentas pendientes antes de suspenderlo otra vez.');
            }

            $cuentas = $usuario->subsystemAccounts()
                ->with('subsystem')
                ->where('estado', SubsystemAccountStatus::Activo)
                ->orderBy('id')
                ->get();

            $usuario->cuentasSuspendidasPorGestor()->sync($cuentas->modelKeys());

            return $this->operations->describir(
                OperationType::SuspensionGestor,
                $usuario,
                $cuentas,
                [
                    'motivo_suspension_gestor' => $motivo,
                    'inicio_suspension_gestor' => $inicioSuspension->toIso8601String(),
                    'fin_suspension_gestor' => $finSuspension?->toIso8601String(),
                    'suspension_programada' => $programada,
                ],
                aplazarSinCuentas: $programada,
            );
        });

        if (! $programada) {
            $this->operations->despachar($operacion);
        }

        $this->audit->log(AuditService::USUARIO_SUSPENDIDO_GESTOR, [
            'gestor_user_id' => $gestorUser->id,
            'nombre_completo' => $gestorUser->nombre_completo,
            'operacion_id' => $operacion->uuid,
            'cuentas' => $operacion->cuentas()->pluck('subsistema')->all(),
            'motivo_suspension_gestor' => $motivo,
            'inicio_suspension_gestor' => $inicioSuspension->toIso8601String(),
            'fin_suspension_gestor' => $finSuspension?->toIso8601String(),
        ]);

        return $operacion->fresh();
    }
}
