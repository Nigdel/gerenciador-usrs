<?php

namespace App\Console\Commands;

use App\Services\UserSuspensionService;
use Illuminate\Console\Command;

class ApplyPendingSuspensionsCommand extends Command
{
    protected $signature = 'accounts:apply-pending-suspensions
                            {--dry-run : Lista las suspensiones agendadas que tocarían sin tocar nada}';

    protected $description = 'Aplica las suspensiones agendadas cuya fecha de inicio ya venció';

    public function handle(UserSuspensionService $service): int
    {
        if ($this->option('dry-run')) {
            $cuentas = $service->cuentasPendientesVencidas();

            if ($cuentas->isEmpty()) {
                $this->components->info('No hay suspensiones agendadas pendientes de aplicar.');

                return self::SUCCESS;
            }

            $this->components->info("Suspensiones a aplicar: {$cuentas->count()}");

            $cuentas->each(function ($cuenta): void {
                $this->components->twoColumnDetail(
                    $cuenta->subsystem?->slug ?? '(desconocido)',
                    sprintf(
                        '%s · %s',
                        $cuenta->credencial_usuario,
                        $cuenta->inicio_suspension?->format('Y-m-d H:i') ?? 'sin fecha',
                    ),
                );
            });

            return self::SUCCESS;
        }

        $resultados = $service->suspenderPendientesVencidas();

        if ($resultados === []) {
            $this->components->info('No hay suspensiones agendadas pendientes de aplicar.');

            return self::SUCCESS;
        }

        $fallos = collect($resultados)->where('exito', false);

        foreach ($resultados as $resultado) {
            $this->line(sprintf(
                '%s %s — %s',
                $resultado['exito'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $resultado['subsistema'],
                $resultado['mensaje'],
            ));
        }

        $this->components->info(sprintf(
            'Suspendidas %d de %d cuentas.',
            count($resultados) - $fallos->count(),
            count($resultados),
        ));

        // Como en la reactivación: un fallo puntual no debe detener al resto,
        // y la cuenta sigue en 'pendiente' para reintentarse la próxima pasada.
        return $fallos->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
