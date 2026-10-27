<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LookupUserRequest;
use App\Http\Resources\GestorUserResource;
use App\Models\GestorUser;
use App\Services\SubsystemServiceRegistry;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Consulta de los datos reutilizables de un CPF antes de darlo de alta.
 *
 * Es el paso previo del alta: si el CPF ya pertenece a un GestorUser, la
 * integración debe saberlo para no intentar crearlo otra vez; si no está en el
 * sistema pero sí en Adagio, devuelve los campos que Adagio ya tiene
 * cadastrados para reutilizarlos en el formulario de alta.
 */
class UserLookupController extends Controller
{
    private const SOURCE_SELF = 'self';

    private const SOURCE_ADAGIO = 'adagio';

    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    /**
     * GET /api/usuarios/consultar?cpf=...
     *
     * La respuesta siempre es 200 con la clave `userdata` presente:
     *  - `null` si el CPF no existe ni en el sistema ni en Adagio.
     *  - `{ source: "self", ... }` si ya es un GestorUser local.
     *  - `{ source: "adagio", ... }` si solo existe en el proveedor de
     *    identidad.
     *
     * Un fallo al consultar Adagio es un 503: no se puede afirmar que el CPF
     * no exista, así que devolver `null` haría que la integración intentara un
     * alta que quizá duplique a alguien que sí está registrado.
     */
    public function show(LookupUserRequest $request): JsonResponse
    {
        $cpf = $request->validated('cpf');

        $gestorUser = GestorUser::query()->where('cpf', $cpf)->first();

        if ($gestorUser !== null) {
            return response()->json([
                'userdata' => [
                    'source' => self::SOURCE_SELF,
                    ...(new GestorUserResource($gestorUser))->resolve($request),
                ],
            ]);
        }

        try {
            $usuario = $this->registry->resolveIdentityProvider()->findByCpf($cpf);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar el CPF en Adagio.',
            ], 503);
        }

        return response()->json([
            'userdata' => $usuario === null ? null : [
                'source' => self::SOURCE_ADAGIO,
                ...$usuario,
            ],
        ]);
    }
}
