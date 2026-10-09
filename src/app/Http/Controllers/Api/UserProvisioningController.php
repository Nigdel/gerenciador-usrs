<?php

namespace App\Http\Controllers\Api;

use App\Enums\OperationType;
use App\Http\Controllers\Api\Concerns\ConservaLaClaveDeIdempotencia;
use App\Http\Controllers\Api\Concerns\RespondeErroresDeProvisionado;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProvisionUserRequest;
use App\Http\Resources\GestorUserResource;
use App\Services\AuditService;
use App\Services\ProvisioningOperationService;
use App\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;

class UserProvisioningController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioningService,
        private readonly ProvisioningOperationService $operationService,
        private readonly AuditService $audit,
    ) {
        $this->usaRespuestasControladas();
    }

    /**
     * POST /api/usuarios/provisionar
     *
     * Crea (o reutiliza, si ya existe en Adagio por CPF) un usuario y lo da
     * de alta en los subsistemas indicados en "subsistemas", o en todos los
     * subsistemas activos si no se especifica ninguno.
     *
     * Desde la Fase 3.2 el alta en los subsistemas sale de la petición: la
     * respuesta es un 201 con el identificador de la operación y las filas
     * 'pendiente', no el resultado final. El código no cambia porque lo que
     * dice sigue siendo cierto —la petición se aceptó y el trabajo quedó
     * encolado—, y cambiarlo rompería a las integraciones que ya lo tratan
     * como alta aceptada.
     */
    use ConservaLaClaveDeIdempotencia;

    use RespondeErroresDeProvisionado;

    public function store(ProvisionUserRequest $request): JsonResponse
    {
        return $this->conErroresControlados(function () use ($request): JsonResponse {
            $resultado = $this->provisioningService->provisionar($request->validated());

            $operacion = $this->operationService->describir(
                OperationType::Alta,
                $resultado['gestor_user'],
                $resultado['subsistemas'],
                $resultado['datos'],
            );

            $this->operationService->despachar($operacion);

            $this->enlazaLaClave($operacion);

            // Igual que en la ruta web, y por el mismo motivo: una entrada de
            // auditoría que solo existe para unos Origenes no sirve para
            // reconstruir qué pasó.
            $this->audit->log(AuditService::USUARIO_CREADO, [
                'gestor_user_id' => $resultado['gestor_user']->id,
                'nombre_completo' => $resultado['gestor_user']->nombre_completo,
                'cpf' => $resultado['gestor_user']->cpf,
                'operacion_id' => $operacion->uuid,
                'subsistemas' => $operacion->cuentas->pluck('subsistema')->all(),
            ]);

            return response()->json([
                'usuario' => new GestorUserResource($resultado['gestor_user']),
                'operacion_id' => $operacion->uuid,
                'tipo' => $operacion->tipo->value,
                'estado' => $operacion->estado->value,
                // Fase 3.2: las filas por cuenta nacen 'pendiente'. Se mantiene
                // el nombre 'subsistemas' porque es el que ya consume la
                // integración, aunque ahora sea la vista de la operación y no
                // un resultado.
                'subsistemas' => $this->operationService->serializar($operacion)['cuentas'],
                // Fase 2.8: si el login se propuso sin poder confirmarlo contra
                // algún subsistema, viaja en la respuesta para que la
                // integración sepa que debe verificarlo antes de entregar las
                // credenciales.
                'login_no_verificado' => $resultado['login_no_verificado'],
            ], 201);
        });
    }
}
