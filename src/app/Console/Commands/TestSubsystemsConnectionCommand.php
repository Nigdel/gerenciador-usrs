<?php

namespace App\Console\Commands;

use App\Services\Subsystems\SubsystemsTestConection;
use Illuminate\Console\Command;

class TestSubsystemsConnectionCommand extends Command
{
    protected $signature = 'subsystems:test-conection';

    protected $description = 'Prueba la conexión con todos los subsistemas activos';

    /**
     * Alias con la ortografía correcta, para no atar el nombre del comando a un
     * typo. El comando responde a los dos nombres.
     *
     * @var array<int, string>
     */
    protected $aliases = ['subsystems:test-connection'];

    public function handle(SubsystemsTestConection $subsistemas): int
    {
        $resultados = $subsistemas->ejecutar();

        if ($resultados->isEmpty()) {
            $this->components->info('No hay subsistemas activos que probar.');

            return self::SUCCESS;
        }

        foreach ($resultados as $resultado) {
            $this->components->twoColumnDetail(
                $resultado['nombre'].' ('.$resultado['slug'].')',
                $this->marca($resultado['probada'], $resultado['exito']).' '.$resultado['mensaje'],
            );
        }

        // Solo hacen fallar el comando los subsistemas que se probaron y no
        // conectaron. Los no probados (sin driver registrado, o con un driver
        // que no admite pruebas) se avisan arriba pero no rompen el exit code:
        // no son un corte de conexión.
        $fallos = $resultados->where(fn (array $resultado): bool => $resultado['probada'] && ! $resultado['exito']);

        if ($fallos->isNotEmpty()) {
            $this->components->error(sprintf(
                'Sin conexión: %s.',
                $fallos->pluck('slug')->implode(', '),
            ));
        }

        $this->components->info(sprintf(
            'Conectaron %d de %d subsistemas probados.',
            $resultados->where('exito', true)->count(),
            $resultados->where('probada', true)->count(),
        ));

        return $fallos->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function marca(bool $probada, bool $exito): string
    {
        return match (true) {
            ! $probada => '<fg=yellow>—</>',
            $exito => '<fg=green>✓</>',
            default => '<fg=red>✗</>',
        };
    }
}
