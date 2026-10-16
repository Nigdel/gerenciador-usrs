<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\Models\Subsystem;
use App\Services\AuditService;
use App\Services\SubsystemServiceRegistry;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Prueba la conexión con todos los subsistemas activos de una sola pasada.
 *
 * El controlador ya sabe probar la conexión de uno (`testConnection`), pero
 * hacerlo de a uno obliga a entrar a cada ficha para saber si algo está caído.
 * Aquí se recorre la tabla entera y se devuelve un resultado por subsistema,
 * pensado para que lo consuma el dashboard y un comando de consola.
 *
 * Un fallo puntual no detiene al resto: cada subsistema se prueba dentro de su
 * propio try/catch, porque un Adagio caído no debe impedir saber que GLPI
 * responde. Un subsistema que no se pudo probar NO se cuenta como conexión
 * fallida, sino como no probada: `last_connection_test_*` guarda lo que la
 * última prueba real dio, y un driver inexistente o que no admite pruebas no
 * debe sobrescribir ese dato con un `false` que nadie midió.
 */
class SubsystemsTestConection
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly AuditService $audit,
    ) {}

    /**
     * Prueba cada subsistema activo y devuelve un resumen por cada uno.
     *
     * @return Collection<int, array{slug: string, nombre: string, probada: bool, exito: bool, mensaje: string}>
     */
    public function ejecutar(): Collection
    {
        return Subsystem::query()
            ->activos()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Subsystem $subsistema): array => $this->probar($subsistema));
    }

    /**
     * @return array{slug: string, nombre: string, probada: bool, exito: bool, mensaje: string}
     */
    private function probar(Subsystem $subsistema): array
    {
        try {
            $servicio = $this->registry->resolve($subsistema->slug);
        } catch (Throwable $exception) {
            return $this->resumen($subsistema, false, false, $exception->getMessage());
        }

        if (! $servicio instanceof SubsystemConnectionInterface) {
            return $this->resumen($subsistema, false, false, 'Este subsistema no admite pruebas de conexión.');
        }

        try {
            $resultado = $servicio->testConnection($subsistema);
        } catch (Throwable $exception) {
            // Un throw aquí (token ausente, api_url vacía) es un fallo de
            // conexión igual que un `success: false`: se registra como probado
            // y fallido, no como no probado, porque el intento sí se hizo.
            report($exception);

            $this->registrarResultado($subsistema, false, $exception->getMessage());

            return $this->resumen($subsistema, true, false, $exception->getMessage());
        }

        $mensaje = $resultado->mensaje
            ?: ($resultado->success ? 'Conexión exitosa' : 'No se pudo completar la prueba de conexión.');

        $this->registrarResultado($subsistema, $resultado->success, $mensaje);

        return $this->resumen($subsistema, true, $resultado->success, $mensaje);
    }

    /**
     * Persiste la última prueba y deja rastro en la bitácora.
     *
     * Mismo criterio que el controlador: se escribe siempre que hubo una
     * prueba real, salga bien o mal, y se audita tanto el éxito como el fallo.
     */
    private function registrarResultado(Subsystem $subsistema, bool $exito, string $mensaje): void
    {
        $subsistema->update([
            'last_connection_test_at' => now(),
            'last_connection_test_success' => $exito,
        ]);

        $this->audit->log(AuditService::SUBSISTEMA_CONEXION_PROBADA, [
            'subsistema_id' => $subsistema->id,
            'nombre' => $subsistema->nombre,
            'slug' => $subsistema->slug,
            'exito' => $exito,
            'mensaje' => $mensaje,
        ]);
    }

    /**
     * @return array{slug: string, nombre: string, probada: bool, exito: bool, mensaje: string}
     */
    private function resumen(Subsystem $subsistema, bool $probada, bool $exito, string $mensaje): array
    {
        return [
            'slug' => $subsistema->slug,
            'nombre' => $subsistema->nombre,
            'probada' => $probada,
            'exito' => $exito,
            'mensaje' => $mensaje,
        ];
    }
}
