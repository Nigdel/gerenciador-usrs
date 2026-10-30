<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ConservaLaClaveDeIdempotencia;
use App\Http\Controllers\Api\Concerns\RespondeErroresDeProvisionado;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReactivateManagerUserApiRequest;
use App\Http\Requests\SuspendManagerUserApiRequest;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Services\GestorUserReactivationService;
use App\Services\GestorUserSuspensionService;
use App\Services\ProvisioningOperationService;
use Illuminate\Http\JsonResponse;

class ManagerSuspensionController extends Controller
{
    use ConservaLaClaveDeIdempotencia;

    use RespondeErroresDeProvisionado;

    public function __construct(
        private readonly GestorUserSuspensionService $suspensionService,
        private readonly GestorUserReactivationService $reactivationService,
        private readonly ProvisioningOperationService $operationService,
    ) {
        $this->usaRespuestasControladas();
    }

    public function suspend(SuspendManagerUserApiRequest $request): JsonResponse
    {
        return $this->conErroresControlados(function () use ($request): JsonResponse {
            $payload = $request->validated();
            $gestorUser = GestorUser::query()->where('cpf', $payload['cpf'])->firstOrFail();
            $operacion = $this->suspensionService->suspender(
                $gestorUser,
                $payload['motivo_suspension'],
                $payload['inicio_suspension'] ?? null,
                $payload['fin_suspension'] ?? null,
            );

            $this->enlazaLaClave($operacion);

            return $this->respuestaOperacion($operacion);
        });
    }

    public function reactivate(ReactivateManagerUserApiRequest $request): JsonResponse
    {
        return $this->conErroresControlados(function () use ($request): JsonResponse {
            $gestorUser = GestorUser::query()
                ->where('cpf', $request->validated('cpf'))
                ->firstOrFail();
            $operacion = $this->reactivationService->reactivar($gestorUser);

            $this->enlazaLaClave($operacion);

            return $this->respuestaOperacion($operacion);
        });
    }

    private function respuestaOperacion(ProvisioningOperation $operacion): JsonResponse
    {
        $operacion->refresh();
        $datos = $this->operationService->serializar($operacion);

        return response()->json([
            'operacion_id' => $operacion->uuid,
            'tipo' => $datos['tipo'],
            'estado' => $datos['estado'],
            'subsistemas' => $datos['cuentas'],
        ]);
    }
}
