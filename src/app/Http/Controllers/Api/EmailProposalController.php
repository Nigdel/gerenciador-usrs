<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondeErroresDeProvisionado;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProposeEmailRequest;
use App\Services\UsernameGeneratorService;
use Illuminate\Http\JsonResponse;

/**
 * Propuesta de email para un usuario que todavía no existe.
 *
 * La integración lo usa antes del alta para enseñar al operador la dirección
 * que se va a crear, sin tener que replicar la convención
 * nombre.primerApellido@empresa.com.br ni la comprobación de disponibilidad.
 */
class EmailProposalController extends Controller
{
    use RespondeErroresDeProvisionado;

    public function __construct(
        private readonly UsernameGeneratorService $usernameGenerator,
    ) {
        $this->usaRespuestasControladas();
    }

    /**
     * GET /api/usuarios/email-propuesto?nombre_completo=...&empresa=...
     *
     * Devuelve la dirección propuesta y, si algún subsistema no pudo
     * responder, el aviso correspondiente. Un fallo del generador (por ejemplo,
     * agotar los candidatos) sale como 422 con el motivo.
     */
    public function show(ProposeEmailRequest $request): JsonResponse
    {
        return $this->conErroresControlados(function () use ($request): JsonResponse {
            return response()->json($this->usernameGenerator->proponerEmail(
                (string) $request->validated('nombre_completo'),
                (string) $request->validated('empresa'),
            ));
        });
    }
}
