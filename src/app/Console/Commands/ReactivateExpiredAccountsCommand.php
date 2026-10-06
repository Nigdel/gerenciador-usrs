<?php

namespace App\Console\Commands;

use App\Services\AccountReactivationService;
use Illuminate\Console\Command;

class ReactivateExpiredAccountsCommand extends Command
{
    protected $signature = 'accounts:reactivate-expired
                            {--dry-run : Lista las cuentas que se reactivarían sin tocar nada}';

    protected $description = 'Reactiva las cuentas suspendidas cuya suspensión ya venció (fin_suspension <= ahora)';

    public function handle(AccountReactivationService $service): int
    {
        if ($this->option('dry-run')) {
            $cuentas = $service->cuentasVencidas();

            if ($cuentas->isEmpty()) {
                $this->components->info('No hay cuentas suspendidas con la suspensión vencida.');

                return self::SUCCESS;
            }

            $this->components->info("Cuentas a reactivar: {$cuentas->count()}");

            $cuentas->each(function ($cuenta): void {
                $this->components->twoColumnDetail(
                    $cuenta->subsystem?->slug ?? '(desconocido)',
                    sprintf(
                        '%s · %s',
                        $cuenta->credencial_usuario,
                        $cuenta->fin_suspension?->format('Y-m-d H:i') ?? 'sin fecha',
                    ),
                );
            });

            return self::SUCCESS;
        }

        $resultados = $service->reactivarVencidas();

        if ($resultados->isEmpty()) {
            $this->components->info('No hay cuentas suspendidas con la suspensión vencida.');

            return self::SUCCESS;
        }

        $fallos = $resultados->where('exito', false);

        $resultados->each(function (array $resultado): void {
            $this->line(sprintf(
                '%s %s — %s',
                $resultado['exito'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $resultado['subsistema'],
                $resultado['mensaje'],
            ));
        });

        $this->components->info(sprintf(
            'Reactivadas %d de %d cuentas.',
            $resultados->count() - $fallos->count(),
            $resultados->count(),
        ));

        // Un fallo puntual en un subsistema no debe dejar el resto sin hacer:
        // el scheduler vuelve a intentarlo en la próxima pasada.
        return $fallos->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
