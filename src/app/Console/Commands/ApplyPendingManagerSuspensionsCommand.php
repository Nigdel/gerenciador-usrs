<?php

namespace App\Console\Commands;

use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Models\ProvisioningOperation;
use App\Services\ProvisioningOperationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ApplyPendingManagerSuspensionsCommand extends Command
{
    protected $signature = 'gestor-users:apply-pending-suspensions
                            {--dry-run : Lista las suspensiones agendadas que tocarían sin tocar nada}';

    protected $description = 'Inicia las suspensiones de gestores cuya fecha ya llegó';

    public function handle(ProvisioningOperationService $operations): int
    {
        $pendientes = ProvisioningOperation::query()
            ->where('tipo', OperationType::SuspensionGestor)
            ->where('estado', OperationStatus::Pendiente)
            ->get()
            ->filter(function (ProvisioningOperation $operacion): bool {
                if (($operacion->payload['suspension_programada'] ?? false) !== true) {
                    return false;
                }

                $inicio = $operacion->payload['inicio_suspension_gestor'] ?? null;

                return is_string($inicio) && Carbon::parse($inicio)->lte(now());
            });

        if ($pendientes->isEmpty()) {
            $this->components->info('No hay suspensiones de gestores pendientes de aplicar.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info("Suspensiones a aplicar: {$pendientes->count()}");

            $pendientes->each(function (ProvisioningOperation $operacion): void {
                $this->components->twoColumnDetail(
                    $operacion->uuid,
                    $operacion->payload['inicio_suspension_gestor'],
                );
            });

            return self::SUCCESS;
        }

        $iniciadas = 0;
        foreach ($pendientes as $operacion) {
            if ($operations->despacharSuspensionGestorProgramada($operacion)) {
                $iniciadas++;
            }
        }

        $this->components->info("Suspensiones de gestores iniciadas: {$iniciadas}.");

        return self::SUCCESS;
    }
}
