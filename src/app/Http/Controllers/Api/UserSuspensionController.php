<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuspendUserRequest;
use App\Services\UserSuspensionService;
use Illuminate\Http\JsonResponse;

class UserSuspensionController extends Controller
{
    public function __construct(
        private readonly UserSuspensionService $suspensionService,
    ) {
    }

    /**
     * POST /api/usuarios/suspender
     *
     * Suspende al usuario (identificado por cpf o usuario) en los
     * subsistemas indicados, o en todos los que tenga cuenta si no se
     * especifica "subsistemas". Si ya estaba suspendido, actualiza
     * inicio/fin/motivo de la suspensión.
     */
    public function store(SuspendUserRequest $request): JsonResponse
    {
        $resultados = $this->suspensionService->suspender($request->validated());

        return response()->json(['subsistemas' => $resultados]);
    }
}
