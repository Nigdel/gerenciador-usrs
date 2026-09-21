<?php

namespace App\Services;

use App\Contracts\IdentityProviderInterface;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta la creación de un usuario a partir de un JSON de entrada:
 *
 *  1. Consulta Adagio (proveedor de identidad) por CPF.
 *     - Si existe: reutiliza nombre_completo, email_personal y cpf ya cadastrados.
 *     - Si no existe: usa los datos del payload y propone un "usuario"
 *       (login) validado contra Adagio (ver UsernameGeneratorService).
 *  2. Crea/actualiza el GestorUser local.
 *  3. Crea el usuario en cada subsistema solicitado (o en todos los activos
 *     si el payload no especifica "subsistemas"), a través del contrato
 *     universal SubsystemServiceInterface.
 */
class UserProvisioningService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly UsernameGeneratorService $usernameGenerator,
    ) {
    }

    /**
     * @param  array  $payload  Formato esperado:
     *   [
     *     'cpf' => '123.456.789-00',
     *     'nombre_completo' => 'Juan Carlos Perez Gomez', // requerido si no existe en Adagio
     *     'email_personal' => '...', 'telefono_personal' => '...', 'telefono_trabajo' => '...',
     *     'direccion_particular' => '...', 'empresa' => 'Acme', 'password_general' => '...',
     *     'subsistemas' => ['adagio', 'glpi', ...], // opcional: si falta, se usan todos los activos
     *   ]
     *
     * @return array{gestor_user: GestorUser, resultados: array<int, array>}
     */
    public function provisionar(array $payload): array
    {
        $datosResueltos = $this->resolverDatosDesdeAdagio($payload);

        $gestorUser = DB::transaction(fn () => $this->guardarUsuarioLocal($datosResueltos));

        $subsistemas = $this->resolverSubsistemas($payload['subsistemas'] ?? null);

        $resultados = [];
        foreach ($subsistemas as $subsystem) {
            $resultados[] = $this->crearEnSubsistema($gestorUser, $subsystem, $datosResueltos);
        }

        return ['gestor_user' => $gestorUser->fresh('subsystemAccounts.subsystem'), 'resultados' => $resultados];
    }

    private function resolverDatosDesdeAdagio(array $payload): array
    {
        $cpf = $payload['cpf'] ?? throw new \InvalidArgumentException('El CPF es obligatorio para provisionar un usuario');

        $adagio = $this->registry->resolve(config('subsystems.proveedor_identidad_slug', 'adagio'));

        $encontradoEnAdagio = $adagio instanceof IdentityProviderInterface ? $adagio->findByCpf($cpf) : null;

        if ($encontradoEnAdagio) {
            // El usuario ya existe en Adagio: se reutilizan sus datos como fuente de verdad.
            return array_merge($payload, [
                'nombre_completo' => $encontradoEnAdagio['nombre_completo'] ?? $payload['nombre_completo'],
                'email_personal' => $encontradoEnAdagio['email_personal'] ?? ($payload['email_personal'] ?? null),
                'cpf' => $encontradoEnAdagio['cpf'] ?? $cpf,
                'usuario' => $encontradoEnAdagio['usuario'] ?? $this->proponerUsuario($payload),
            ]);
        }

        // No existe en Adagio: se generan los datos a partir del payload y se propone login.
        if (empty($payload['nombre_completo']) || empty($payload['empresa'])) {
            throw new \InvalidArgumentException('nombre_completo y empresa son obligatorios cuando el CPF no existe en Adagio');
        }

        return array_merge($payload, [
            'cpf' => $cpf,
            'usuario' => $payload['usuario'] ?? $this->proponerUsuario($payload),
        ]);
    }

    private function proponerUsuario(array $payload): string
    {
        return $this->usernameGenerator->proponer($payload['nombre_completo'], $payload['empresa']);
    }

    private function guardarUsuarioLocal(array $datos): GestorUser
    {
        return GestorUser::updateOrCreate(
            ['cpf' => $datos['cpf']],
            [
                'nombre_completo' => $datos['nombre_completo'],
                'password_general' => $datos['password_general'] ?? str()->random(16),
                'telefono_personal' => $datos['telefono_personal'] ?? null,
                'telefono_trabajo' => $datos['telefono_trabajo'] ?? null,
                'email_personal' => $datos['email_personal'] ?? null,
                'direccion_particular' => $datos['direccion_particular'] ?? null,
                'usuario' => $datos['usuario'],
                'empresa' => $datos['empresa'] ?? null,
            ],
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Subsystem>
     */
    private function resolverSubsistemas(?array $slugs)
    {
        $query = Subsystem::query()->activos();

        if (! empty($slugs)) {
            $query->whereIn('slug', $slugs);
        }

        return $query->get();
    }

    private function crearEnSubsistema(GestorUser $gestorUser, Subsystem $subsystem, array $datos): array
    {
        $servicio = $this->registry->resolve($subsystem->slug);
        $resultado = $servicio->createUser($datos, $subsystem);

        $account = UserSubsystemAccount::updateOrCreate(
            ['gestor_user_id' => $gestorUser->id, 'subsystem_id' => $subsystem->id],
            [
                'credencial_usuario' => $resultado->credencialUsuario ?? $datos['usuario'],
                'external_account_id' => $resultado->externalAccountId,
                'fecha_creacion' => now(),
                'estado' => $resultado->success ? ($resultado->estado ?? 'activo') : 'deshabilitado',
                'meta' => $resultado->raw,
            ],
        );

        return [
            'subsistema' => $subsystem->slug,
            'exito' => $resultado->success,
            'mensaje' => $resultado->mensaje,
            'cuenta' => $account,
        ];
    }
}
