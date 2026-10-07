<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProvisioningOperation;
use App\Services\ProvisioningOperationService;
use Illuminate\Http\JsonResponse;

/**
 * Consulta del estado de una operación por API (Sprint 1.3).
 *
 * Es el cierre del circuito con el que arrancan las integraciones: el alta
 * responde con un `operacion_id` y encola el trabajo real, así que sin esto la
 * integración no tiene forma de saber si las cuentas se crearon o no.
 */
class OperationController extends Controller
{
    public function __construct(
        private readonly ProvisioningOperationService $operaciones,
    ) {}

    /**
     * GET /api/operaciones/{uuid}
     *
     * Un token solo ve las operaciones que él mismo originó. La alternativa —
     * verlas todas— dejaría que una integración leiera el trabajo de otra, y
     * `serializar()` no filtra por usuario: expone el subsistema, el mensaje de
     * error y el estado de cada cuenta de cualquier operación de la base.
     *
     * Lo que no se puede ver aquí tampoco es accesible de ninguna otra manera
     * por API, así que el 404 es coherente con lo que el 403 revealing sería:
     * que la operación existe ya es información de otro.
     *
     * Responde 404 —no 403— cuando la operación existe pero es de otro origen o
     * no existe, por el mismo motivo que en el polling web.
     */
    public function show(string $uuid): JsonResponse
    {
        abort_unless(
            ProvisioningOperation::query()
                ->where('uuid', $uuid)
                ->where('origen', 'api')
                ->exists(),
            404,
        );

        return response()->json(
            $this->operaciones->serializar(ProvisioningOperation::query()->where('uuid', $uuid)->firstOrFail()),
        );
    }
}
