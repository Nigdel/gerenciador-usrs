<?php

namespace App\Observers;

use App\Models\AccountStateLog;
use App\Models\UserSubsystemAccount;
use App\Support\ActorContext;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Escribe el histórico de cada cuenta en subsistema.
 *
 * Va como observer y no instrumentado en los servicios a propósito: hoy las
 * escrituras están repartidas entre el servicio de suspensión, el de
 * reactivación, el de aprovisionamiento, el controlador de subsistemas y el
 * CRUD de cuentas. Añadir la llamada en cada uno sería la misma línea cinco
 * veces, y bastaría con que el próximo cambio escribiera por otro sitio para
 * que el histórico dejara de ser fiable.
 *
 * Como los servicios hacen confirm-before-persist, aquí solo se registra lo que
 * de verdad ocurrió: si el subsistema remoto no confirmó, la fila no se toca y
 * tampoco hay entrada.
 */
class AccountStateLogObserver
{
    /**
     * 'meta' queda fuera a propósito: guarda el payload crudo del último
     * llamado a la API, no un cambio de negocio, y puede traer datos
     * voluminosos o sensibles que no queremos duplicar en cada línea.
     */
    private const ATRIBUTOS_IGNORADOS = ['updated_at', 'created_at', 'meta'];

    /** Verbo para el resumen legible, según el estado al que se pasa. */
    private const VERBOS = [
        'activo' => 'Activa',
        'pendiente' => 'Programa la suspensión de',
        'suspendido' => 'Suspende',
        'deshabilitado' => 'Deshabilita',
        'borrado' => 'Elimina',
    ];

    public function created(UserSubsystemAccount $cuenta): void
    {
        $cambios = $this->normalizarAlta($cuenta);

        $this->registrar($cuenta, AccountStateLog::CREATED, $cambios, sprintf(
            'Crea la cuenta (%s)',
            $this->estadoDe($cuenta),
        ));
    }

    public function updated(UserSubsystemAccount $cuenta): void
    {
        $cambios = $this->cambiosDe($cuenta);

        // Un update() que no cambia nada no es un hecho que auditar: llenaría el
        // histórico de entradas vacías.
        if ($cambios === []) {
            return;
        }

        $this->registrar($cuenta, AccountStateLog::UPDATED, $cambios, $this->describir($cambios));
    }

    public function deleted(UserSubsystemAccount $cuenta): void
    {
        // Para cuando corre este observer la fila ya no existe, así que su id
        // no puede guardarse o la FK falla. Se guarda a null y se deja
        // constancia de qué cuenta era en 'cambios', además del usuario y el
        // subsistema que ya vienen desnormalizados en la entrada.
        $this->registrar($cuenta, AccountStateLog::DELETED, [
            'cuenta' => ['desde' => $cuenta->credencial_usuario, 'hasta' => null],
        ], sprintf(
            'Elimina la cuenta (%s)',
            $this->estadoDe($cuenta),
        ));
    }

    /**
     * getDirty() solo trae el valor nuevo; el viejo está en getOriginal().
     *
     * @return array<string, array<string, mixed>>
     */
    private function cambiosDe(UserSubsystemAccount $cuenta): array
    {
        $salida = [];

        foreach ($this->filtrar($cuenta->getDirty()) as $atributo => $valor) {
            $salida[$atributo] = [
                'desde' => $this->aTexto($cuenta->getOriginal($atributo)),
                'hasta' => $this->aTexto($valor),
            ];
        }

        return $salida;
    }

    /**
     * En un alta getDirty() ya viene vacío, así que se toma lo relevante de los
     * atributos actuales como valores nuevos.
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizarAlta(UserSubsystemAccount $cuenta): array
    {
        $valores = array_filter([
            'estado' => $cuenta->estado,
            'credencial_usuario' => $cuenta->credencial_usuario,
            'external_account_id' => $cuenta->external_account_id,
            'inicio_suspension' => $cuenta->inicio_suspension,
            'fin_suspension' => $cuenta->fin_suspension,
            'motivo_suspension' => $cuenta->motivo_suspension,
        ], fn ($valor) => $valor !== null);

        $salida = [];

        foreach ($valores as $atributo => $valor) {
            $salida[$atributo] = ['desde' => null, 'hasta' => $this->aTexto($valor)];
        }

        return $salida;
    }

    /**
     * @param  array<string, mixed>  $dirty
     * @return array<string, mixed>
     */
    private function filtrar(array $dirty): array
    {
        return array_diff_key($dirty, array_flip(self::ATRIBUTOS_IGNORADOS));
    }

    /**
     * @param  array<string, array<string, mixed>>  $cambios
     */
    private function describir(array $cambios): string
    {
        $verbos = self::VERBOS;
        $destino = $cambios['estado']['hasta'] ?? null;

        if ($destino !== null) {
            return sprintf(
                '%s la cuenta (%s)',
                $verbos[$destino] ?? 'Actualiza',
                $destino,
            );
        }

        return 'Actualiza '.Str::of(implode(', ', array_keys($cambios)))
            ->replace(['_', '.'], ' ')
            ->lower();
    }

    private function estadoDe(UserSubsystemAccount $cuenta): string
    {
        return $cuenta->estado?->value ?? (string) $cuenta->estado;
    }

    private function aTexto(mixed $valor): mixed
    {
        if ($valor instanceof \BackedEnum) {
            return $valor->value;
        }

        if ($valor instanceof DateTimeInterface) {
            return $valor->format(DateTimeInterface::ATOM);
        }

        return $valor;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cambios
     */
    private function registrar(UserSubsystemAccount $cuenta, string $evento, array $cambios, string $descripcion): void
    {
        $actor = ActorContext::actual();

        try {
            AccountStateLog::create([
                'user_subsystem_account_id' => $evento === AccountStateLog::DELETED
                    ? null
                    : $cuenta->id,
                'gestor_user_id' => $cuenta->gestor_user_id,
                'subsystem_id' => $cuenta->subsystem_id,
                'evento' => $evento,
                'cambios' => $cambios ?: null,
                'actor_id' => $actor?->id,
                'actor_nombre' => $actor?->name,
                'origen' => ActorContext::origen(),
                'descripcion' => $descripcion,
            ]);
        } catch (\Throwable $exception) {
            // Un fallo del histórico no debe tumbar la operación que lo ha
            // provocado — una suspensión no se deshace por no poderse auditar.
            report($exception);
        }
    }
}
