<?php

namespace App\Console\Commands;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Models\ProvisioningOperation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Marca como fallidas las operaciones que llevan demasiado tiempo 'en curso'.
 *
 * Una operación se queda 'en curso' porque le queda alguna cuenta pendiente.
 * Si el worker muere a mitad —se cae el contenedor, se despliega, la llamada al
 * subsistema se cuelga hasta que el job agota los reintentos— esa cuenta nunca
 * vuelve a tocarse y la operación se queda 'en curso' para siempre: el usuario
 * que la abrió ve un trabajo que no termina, y mientras tanto el bloqueo del
 * Sprint 1.4 impide abrir otra operación sobre esa misma persona.
 *
 * `updated_at` sirve de latido sin añadir una columna: lo mueve cualquier
 * `save()` de la cabecera o de sus cuentas, así que una operación sana se
 * renueva sola mientras avanza.
 *
 * Las cuentas pendientes se pasan a 'error' y no se borran: su mensaje es lo
 * que explica el cierre, y el reintento del Sprint 1.2 las necesita tal cual.
 */
class ExpireStuckOperationsCommand extends Command
{
    protected $signature = 'operations:expire-stuck
                            {--dry-run : Lista las operaciones que se cerrarían sin tocar nada}';

    protected $description = 'Cierra como fallidas las operaciones en curso sin actividad reciente';

    public function handle(): int
    {
        $minutos = (int) config('operations.stuck_minutes', 30);
        $limite = Carbon::now()->subMinutes($minutos);

        $atascadas = ProvisioningOperation::query()
            ->where('estado', OperationStatus::EnCurso)
            ->where('updated_at', '<=', $limite)
            ->get();

        if ($atascadas->isEmpty()) {
            $this->components->info(sprintf(
                'No hay operaciones atascadas (umbral: %d min).',
                $minutos,
            ));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '%d operación(es) se cerrarían (umbral: %d min):',
                $atascadas->count(),
                $minutos,
            ));

            $atascadas->each(fn (ProvisioningOperation $operacion) => $this->components->twoColumnDetail(
                $operacion->tipo->value,
                sprintf(
                    '%s · %s · sin actividad desde %s',
                    $operacion->uuid,
                    $operacion->estado->value,
                    $operacion->updated_at->diffForHumans(),
                ),
            ));

            return self::SUCCESS;
        }

        foreach ($atascadas as $operacion) {
            $this->cerrar($operacion, $minutos);
        }

        return self::SUCCESS;
    }

    private function cerrar(ProvisioningOperation $operacion, int $minutos): void
    {
        $motivo = sprintf('Sin actividad durante %d minutos: el trabajo se ha dado por perdido.', $minutos);

        $operacion->cuentas()
            ->where('estado', OperationAccountStatus::Pendiente)
            ->update(['estado' => OperationAccountStatus::Error, 'mensaje' => $motivo]);

        // forceFill y no update() a propósito: 'estado' está en $fillable, pero
        // el recuento tiene que salir de las filas que acabamos de tocar y no
        // de los contadores desnormalizados, que aquí son precisamente lo que
        // se sabe desfasado. Cerrar() no sirve: no vuelve a contar.
        $operacion->forceFill([
            'estado' => OperationStatus::Fallida,
            'terminada_at' => now(),
            'pendientes' => 0,
            'exitos' => $operacion->cuentas()->where('estado', OperationAccountStatus::Ok)->count(),
            'errores' => $operacion->cuentas()->where('estado', OperationAccountStatus::Error)->count(),
        ])->save();

        $this->components->twoColumnDetail(
            $operacion->uuid,
            sprintf('%s · cerrada como fallida · %s', $operacion->tipo->value, $motivo),
        );
    }
}
