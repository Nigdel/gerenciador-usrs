<?php

namespace App\Http\Controllers\Api;

use App\Enums\OperationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuspendUserRequest;
use App\Services\ProvisioningOperationService;
use App\Services\UserSuspensionService;
use Illuminate\Http\JsonResponse;

class UserSuspensionController extends Controller
{
    public function __construct(
        private readonly UserSuspensionService $suspensionService,
        private readonly ProvisioningOperationService $operationService,
    ) {}

    /**
     * POST /api/usuarios/suspender
     *
     * Suspende al usuario (identificado por cpf o usuario) en los
     * subsistemas indicados, o en todos los que tenga cuenta si no se
     * especifica "subsistemas". Si ya estaba suspendido, actualiza
     * inicio/fin/motivo de la suspensión.
     *
     * Desde la Fase 3.2 sale de la petición: la respuesta lleva el
     * identificador de la operación y las filas 'pendiente' en vez del
     * resultado final.
     */
    public function store(SuspendUserRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $gestorUser = $this->suspensionService->localizarUsuario($payload);

        // Antes de resolver cuentas: es lo único que puede fallar sin haber
        // tocado nada, y el error debe ser el del bloqueo y no el de un
        // usuario sin cuentas en el subsistema pedido.

        $cuentas = $this->suspensionService->cuentasASuspender(
            $gestorUser,
            $payload['subsistemas'] ?? null,
        );

        $datos = $this->suspensionService->datosDeSuspension([
            'motivo_suspension' => $payload['motivo_suspension'] ?? null,
            'inicio_suspension' => $payload['inicio_suspension'] ?? null,
            'fin_suspension' => $payload['fin_suspension'] ?? null,
        ]);

        $operacion = $this->operationService->describir(
            OperationType::Suspension,
            $gestorUser,
            $cuentas,
            ['motivo_suspension' => $datos['motivo_suspension']],
            array_fill_keys($cuentas->pluck('id')->all(), $datos),
        );

        $this->operationService->despachar($operacion);

        return response()->json([
            'operacion_id' => $operacion->uuid,
            'tipo' => $operacion->tipo->value,
            'estado' => $operacion->estado->value,
            'subsistemas' => $this->operationService->serializar($operacion)['cuentas'],
        ]);
    }
}
