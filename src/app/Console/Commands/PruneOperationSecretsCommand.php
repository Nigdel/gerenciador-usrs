<?php

namespace App\Console\Commands;

use App\Enums\OperationStatus;
use App\Models\ProvisioningOperation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Quita la contraseña general de las operaciones cuyo plazo de guarda ya pasó.
 *
 * El payload de una operación va cifrado, pero mientras conserve la contraseña
 * en claro sigue siendo un secreto útil para cualquiera que acceda a la base.
 * `cerrar()` ya la borra en cuanto la operación se completa; este comando es la
 * red de seguridad para lo que queda: operaciones completadas antes de que esa
 * poda existiera, y fallidas a las que nadie volvió a reintentar.
 *
 * Ambas se tratan igual a propósito. Podar una fallida antes de tiempo dejaría
 * su reintento sin contraseña, así que el plazo por defecto
 * (`operations.secret_ttl_hours`) es holgado: es tiempo de sobra para que un
 * operador reintente, no una ventana de oportunidad.
 */
class PruneOperationSecretsCommand extends Command
{
    protected $signature = 'operations:prune-secrets
                            {--dry-run : Lista las operaciones que se limpiarían sin tocar nada}';

    protected $description = 'Elimina la contraseña general del payload de las operaciones terminadas hace más del TTL';

    public function handle(): int
    {
        $horas = (int) config('operations.secret_ttl_hours', 72);
        $limite = Carbon::now()->subHours($horas);

        $pendientes = ProvisioningOperation::query()
            ->whereIn('estado', [OperationStatus::Completada, OperationStatus::Fallida])
            ->whereNotNull('terminada_at')
            ->where('terminada_at', '<=', $limite)
            ->get();

        if ($pendientes->isEmpty()) {
            $this->components->info(sprintf(
                'No hay operaciones con secretos caducados (TTL: %d h).',
                $horas,
            ));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '%d operación(es) se limpiarían (TTL: %d h):',
                $pendientes->count(),
                $horas,
            ));

            $pendientes->each(fn (ProvisioningOperation $operacion) => $this->components->twoColumnDetail(
                $operacion->tipo->value,
                sprintf(
                    '%s · %s · terminada %s',
                    $operacion->uuid,
                    $operacion->estado->value,
                    $operacion->terminada_at->diffForHumans(),
                ),
            ));

            return self::SUCCESS;
        }

        $limpiadas = 0;

        foreach ($pendientes as $operacion) {
            $limpiadas += $operacion->podarSecretos() ? 1 : 0;
        }

        $this->components->info(sprintf(
            'Se han limpiado %d de %d operación(es) caducadas.',
            $limpiadas,
            $pendientes->count(),
        ));

        return self::SUCCESS;
    }
}
