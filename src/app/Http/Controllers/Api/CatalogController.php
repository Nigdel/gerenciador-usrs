<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subsystem;
use App\Services\SubsystemCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo público para integraciones: subsistemas disponibles, configuración
 * adicional de cada uno y empresas aceptadas en el alta.
 */
class CatalogController extends Controller
{
    public function __construct(
        private readonly SubsystemCatalogService $catalog,
    ) {}

    /**
     * GET /api/subsistemas
     */
    public function subsystems(): JsonResponse
    {
        $subsistemas = Subsystem::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Subsystem $subsystem) => $this->catalog->resumen($subsystem))
            ->all();

        return response()->json(['subsistemas' => $subsistemas]);
    }

    /**
     * GET /api/subsistema/{subsistema}
     *
     * El identificador puede ser el id numérico o el slug, para que la
     * integración no tenga que resolver uno a partir del otro.
     */
    public function subsystem(string $subsistema): JsonResponse
    {
        $subsystem = ctype_digit($subsistema)
            ? Subsystem::query()->find((int) $subsistema)
            : Subsystem::query()->where('slug', $subsistema)->first();

        abort_if($subsystem === null, 404, 'Subsistema no encontrado.');

        return response()->json([
            ...$this->catalog->resumen($subsystem),
            'configuracion_adicional' => $this->catalog->configuracionAdicional($subsystem),
        ]);
    }

    /**
     * GET /api/empresas
     */
    public function companies(): JsonResponse
    {
        return response()->json([
            'empresas' => array_values((array) config('subsystems.empresas', [])),
        ]);
    }
}
