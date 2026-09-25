<?php

namespace App\Services;

use App\Contracts\IdentityProviderInterface;
use App\Contracts\SubsystemServiceInterface;
use App\Models\Subsystem;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resuelve la clase de servicio concreta (App\Services\Subsystems\*) a
 * partir del slug de un subsistema, usando config/subsystems.php.
 * Este es el único punto donde se traduce "slug" -> "clase"; los
 * orquestadores nunca instancian un servicio de subsistema directamente.
 */
class SubsystemServiceRegistry
{
    public function resolve(string $slug): SubsystemServiceInterface
    {
        $drivers = config('subsystems.drivers', []);

        if (! isset($drivers[$slug])) {
            throw new InvalidArgumentException("No existe un driver registrado para el subsistema [{$slug}]");
        }

        $service = App::make($drivers[$slug]);

        if (! $service instanceof SubsystemServiceInterface) {
            throw new InvalidArgumentException("El driver del subsistema [{$slug}] no implementa SubsystemServiceInterface");
        }

        return $service;
    }

    /**
     * Localiza el subsistema marcado como proveedor de identidad
     * (es_proveedor_identidad = true en la tabla `subsystems`, ej. Adagio)
     * y devuelve su servicio ya resuelto. Fuente única de verdad: la BD,
     * no un slug hardcodeado en config.
     */
    public function resolveIdentityProvider(): IdentityProviderInterface
    {
        $subsystem = Subsystem::proveedorIdentidad()->activos()->first();

        if (! $subsystem) {
            throw new RuntimeException(
                'No hay ningún subsistema marcado como es_proveedor_identidad=true (ej. Adagio) o está inactivo',
            );
        }

        $servicio = $this->resolve($subsystem->slug);

        if (! $servicio instanceof IdentityProviderInterface) {
            throw new RuntimeException(
                "El subsistema proveedor de identidad [{$subsystem->slug}] debe implementar IdentityProviderInterface",
            );
        }

        return $servicio;
    }
}
