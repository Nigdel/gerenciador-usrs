<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Services\IdempotencyService;
use Illuminate\Console\Command;

/**
 * Borra las claves de idempotencia cuyo plazo de reintento ya pasó.
 *
 * Sin esto la tabla crece sin límite. Y no es solo limpieza: pasada la ventana
 * del TTL, una clave deja de poder repetir su respuesta y la misma
 * petición tiene que ejecutarse de verdad. Escribir esto es lo que convierte el
 * TTL en una regla en lugar de un comentario.
 */
class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune
                            {--dry-run : Cuenta las claves que se borrarían sin tocar nada}
                            {--limit= : Máximo de claves a borrar en esta pasada}';

    protected $description = 'Elimina las claves de idempotencia caducadas';

    public function handle(IdempotencyService $servicio): int
    {
        $limite = $this->option('limit');
        $limite = $limite === null ? null : max(1, (int) $limite);
        $horas = (int) config('idempotency.ttl_hours', 24);

        $caducadas = IdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->when($limite, fn ($q) => $q->limit($limite))
            ->count();

        if ($caducadas === 0) {
            $this->components->info(sprintf('No hay claves caducadas (TTL: %d h).', $horas));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '%d clave(s) se borrarían (TTL: %d h).',
                $caducadas,
                $horas,
            ));

            return self::SUCCESS;
        }

        $borradas = $servicio->purgar($limite);

        $this->components->info(sprintf(
            'Se han borrado %d de %d clave(s) caducadas.',
            $borradas,
            $caducadas,
        ));

        return self::SUCCESS;
    }
}
