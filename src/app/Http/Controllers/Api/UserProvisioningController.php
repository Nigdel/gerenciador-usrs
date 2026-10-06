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
    ) {}

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
            // Fase 2.8: si el login se propuso sin poder confirmarlo contra
            // algún subsistema, viaja en la respuesta para que la integración
            // sepa que debe verificarlo antes de entregar las credenciales.
            'login_no_verificado' => $resultado['login_no_verificado'],
        ], 201);
    }
}
