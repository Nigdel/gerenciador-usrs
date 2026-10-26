<?php

namespace App\Console\Commands;

use App\Services\GestorUserReactivationService;
use Illuminate\Console\Command;

class ReactivateExpiredGestorUsersCommand extends Command
{
    protected $signature = 'gestor-users:reactivate-expired
                            {--dry-run : Lista los gestores que se reactivarían sin tocar nada}';

    protected $description = 'Reactiva a los gestores suspendidos cuya fecha de fin de suspensión ya venció';

    public function handle(GestorUserReactivationService $service): int
    {
        if ($this->option('dry-run')) {
            $gestores = $service->gestoresVencidos();

            if ($gestores->isEmpty()) {
                $this->components->info('No hay gestores suspendidos con la suspensión vencida.');

                return self::SUCCESS;
            }

            $this->components->info("Gestores a reactivar: {$gestores->count()}");

            $gestores->each(function ($gestor): void {
                $this->components->twoColumnDetail(
                    $gestor->usuario ?? $gestor->nombre_completo,
                    $gestor->fin_suspension_gestor?->format('Y-m-d H:i') ?? 'sin fecha',
                );
            });

            return self::SUCCESS;
        }

        $resultados = $service->reactivarVencidos();

        if ($resultados->isEmpty()) {
            $this->components->info('No hay gestores suspendidos con la suspensión vencida.');

            return self::SUCCESS;
        }

        $fallos = $resultados->where('exito', false);

        $resultados->each(function (array $resultado): void {
            $this->line(sprintf(
                '%s %s — %s',
                $resultado['exito'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $resultado['gestor']->usuario ?? $resultado['gestor']->nombre_completo,
                $resultado['mensaje'],
            ));
        });

        $this->components->info(sprintf(
            'Reactivados %d de %d gestores.',
            $resultados->count() - $fallos->count(),
            $resultados->count(),
        ));

        // Un fallo puntual no debe dejar al resto sin reactivar: el scheduler
        // vuelve a intentarlo en la próxima pasada.
        return $fallos->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
