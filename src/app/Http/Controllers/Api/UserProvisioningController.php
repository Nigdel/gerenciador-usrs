<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProvisionUserRequest;
use App\Http\Resources\GestorUserResource;
use App\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;

class UserProvisioningController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioningService,
    ) {
    }

    /**
     * POST /api/usuarios/provisionar
     *
     * Crea (o reutiliza, si ya existe en Adagio por CPF) un usuario y lo da
     * de alta en los subsistemas indicados en "subsistemas", o en todos los
     * subsistemas activos si no se especifica ninguno.
     */
    public function store(ProvisionUserRequest $request): JsonResponse
    {
        $resultado = $this->provisioningService->provisionar($request->validated());

        return response()->json([
            'usuario' => new GestorUserResource($resultado['gestor_user']),
            'subsistemas' => $resultado['resultados'],
        ], 201);
    }
}
