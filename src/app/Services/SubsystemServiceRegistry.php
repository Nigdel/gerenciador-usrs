<?php

namespace App\Services;

use App\Contracts\SubsystemServiceInterface;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

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
}
