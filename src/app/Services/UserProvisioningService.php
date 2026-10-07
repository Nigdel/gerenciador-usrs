<?php

namespace App\Services;

use App\Contracts\IdentityProviderInterface;
use App\DTO\SubsystemOperationResult;
use App\Exceptions\ProvisioningException;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orquesta la creación de un usuario a partir de un JSON de entrada:
 *
 *  1. Consulta Adagio (proveedor de identidad) por CPF.
 *     - Si existe: reutiliza nombre_completo, email_personal y cpf ya cadastrados.
 *     - Si no existe: usa los datos del payload y propone un "usuario"
 *       (login) validado contra Adagio (ver UsernameGeneratorService).
 *  2. Crea/actualiza el GestorUser local.
 *  3. Devuelve los subsistemas donde hay que darlo de alta, ya resueltos.
 *
 * Desde la Fase 3.2 el paso 3 **no se ejecuta aquí**: provisionar() se queda
 * en el camino síncrono (una consulta a Adagio, un INSERT local y la propuesta
 * de login, que además necesita el nombre para salir bien) y devuelve la lista
 * de subsistemas para que el paso 3 se encole, un job por subsistema. El alta
 * en sí vive en crearEnSubsistema(), que es lo que el job llama.
 */
class UserProvisioningService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly UsernameGeneratorService $usernameGenerator,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
    ) {}

    /**
     * @param  array  $payload  Formato esperado:
     *                          [
     *                          'cpf' => '123.456.789-00',
     *                          'nombre_completo' => 'Juan Carlos Perez Gomez', // requerido si no existe en Adagio
     *                          'email_personal' => '...', 'telefono_personal' => '...', 'telefono_trabajo' => '...',
     *                          'direccion_particular' => '...', 'empresa' => 'Acme', 'password_general' => '...',
     *                          'subsistemas' => ['adagio', 'glpi', ...], // opcional: si falta, se usan todos los activos
     *                          ]
     * @return array{gestor_user: GestorUser, subsistemas: Collection<int, Subsystem>, datos: array, login_no_verificado: ?string}
     */
    public function provisionar(array $payload): array
    {
        $datosResueltos = $this->resolverDatosDesdeAdagio($payload);

        $gestorUser = DB::transaction(fn () => $this->guardarUsuarioLocal($datosResueltos));

        return [
            'gestor_user' => $gestorUser->fresh('subsystemAccounts.subsystem'),
            // Los subsistemas donde hay que dar de alta, ya resueltos. No se
            // da de alta nada aquí: eso lo hace un job por subsistema (Fase
            // 3.2), para que la respuesta al operador no espere a N llamadas
            // salientes.
            'subsistemas' => $this->resolverSubsistemas($payload['subsistemas'] ?? null),
            // Los datos ya resueltos, porque el job los necesita y no puede
            // volver a preguntar a Adagio: hacerlo lo haría pedir un login
            // distinto al que se eligió en su momento.
            'datos' => $datosResueltos,
            // Aviso del generador de login (Fase 2.8): si algún subsistema no
            // pudo responder, el login se eligió sin confirmarlo contra él y el
            // operador debería saberlo antes de dar el alta por buena.
            'login_no_verificado' => $datosResueltos['login_no_verificado'] ?? null,
        ];
    }

    private function resolverDatosDesdeAdagio(array $payload): array
    {
        $cpf = $payload['cpf'] ?? throw new ProvisioningException('El CPF es obligatorio para provisionar un usuario');

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
            throw new ProvisioningException('nombre_completo y empresa son obligatorios cuando el CPF no existe en Adagio');
        }

        $propuesta = $this->proponerUsuario($payload);

        return array_merge($payload, [
            'cpf' => $cpf,
            'usuario' => $payload['usuario'] ?? $propuesta['usuario'],
            'login_no_verificado' => $propuesta['mensaje'],
        ]);
    }

    /**
     * @return array{usuario: string, mensaje: ?string}
     */
    private function proponerUsuario(array $payload): array
    {
        return $this->usernameGenerator->proponerConAviso($payload['nombre_completo'], $payload['empresa']);
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
     * @return Collection<int, Subsystem>
     */
    private function resolverSubsistemas(?array $slugs)
    {
        $query = Subsystem::query()->activos();

        if (! empty($slugs)) {
            $query->whereIn('slug', $slugs);
        }

        return $query->get();
    }

    /**
     * Da de alta al usuario en un subsistema concreto. Es la unidad de trabajo
     * que el job ProcessOperationAccount ejecuta (Fase 3.2).
     *
     * @return array{subsistema: string, exito: bool, mensaje: ?string, cuenta: UserSubsystemAccount}
     */
    public function crearEnSubsistema(GestorUser $gestorUser, Subsystem $subsystem, array $datos, array $subsystemConfig = []): array
    {
        $datosParaSubsistema = $this->mergeSubsystemConfig($datos, $subsystem, $subsystemConfig);
        $servicio = $this->registry->resolve($subsystem->slug);
        $resultado = $servicio->createUser($datosParaSubsistema, $subsystem);

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

    private function mergeSubsystemConfig(array $datos, Subsystem $subsystem, array $subsystemConfig): array
    {
        if (empty($subsystemConfig[$subsystem->slug] ?? null)) {
            return $datos;
        }

        $datos['subsystem_config'] = $subsystemConfig[$subsystem->slug];

        return $datos;
    }

    /**
     * Restablece la contraseña de todas las cuentas del usuario.
     *
     * Genera UNA sola contraseña para todas: es lo que espera el usuario final
     * (el mismo login, la misma clave) y evita tener que volver a decirla tras
     * cada iteración. Se devuelve aquí para que el controlador pueda mostrarla
     * una única vez y no almacenarla.
     *
     * Los resultados salen como arrays y no como DTOs porque quien los consume
     * (controlador y vista) ya trabaja con la forma de provisionar(), que usa
     * 'exito' y 'subsistema'.
     *
     * @return array{contrasena: string, resultados: array<int, array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: UserSubsystemAccount}>}
     */
    public function resetAllPasswords(GestorUser $gestorUser): array
    {
        $gestorUser->load('subsystemAccounts.subsystem');

        $nuevaContrasena = $this->passwordGenerator->generar();
        $results = [];

        // La contraseña general se actualiza primero y en bloque: si fallara, no
        // se toca ningún subsistema y no queda el usuario con la general cambiada
        // y las cuentas sin cambiar.
        $gestorUser->forceFill(['password_general' => $nuevaContrasena])->save();

        foreach ($gestorUser->subsystemAccounts as $userAccount) {
            try {
                $resultado = $this->resetPassword($gestorUser, $userAccount, $nuevaContrasena);
            } catch (RuntimeException $exception) {
                report($exception);

                $resultado = SubsystemOperationResult::fail($exception->getMessage());
            }

            $results[] = [
                'subsistema' => $userAccount->subsystem?->slug,
                'exito' => $resultado->success,
                'mensaje' => $resultado->mensaje,
                'cuenta' => $userAccount,
            ];
        }

        return ['contrasena' => $nuevaContrasena, 'resultados' => $results];
    }

    /**
     * Restablece la contraseña de una cuenta concreta.
     *
     * Si no se indica contraseña se genera una, para poder llamarla también desde
     * un futuro botón "una cuenta".
     */
    public function resetPassword(
        GestorUser $gestorUser,
        UserSubsystemAccount $userAccount,
        ?string $nuevaContrasena = null,
    ): SubsystemOperationResult {
        $subsystem = $userAccount->subsystem;

        if ($subsystem === null) {
            return SubsystemOperationResult::fail(
                'La cuenta no tiene un subsistema asociado.',
                ['subsystem' => 'desconocido'],
            );
        }

        try {
            $servicio = $this->registry->resolve($subsystem->slug);
        } catch (\Throwable $exception) {
            return SubsystemOperationResult::fail(
                $exception->getMessage(),
                ['subsystem' => $subsystem->slug],
            );
        }

        $resultado = $servicio->resetPassword($userAccount, $nuevaContrasena ?? $this->passwordGenerator->generar());

        if (! $resultado->success) {
            return SubsystemOperationResult::fail(
                $resultado->mensaje ?? 'No se pudo restablecer la contraseña.',
                $resultado->raw ?? [],
            );
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $userAccount->credencial_usuario,
            externalAccountId: $userAccount->external_account_id,
            estado: $userAccount->estado?->value,
            mensaje: 'Contraseña restablecida.',
            raw: $resultado->raw ?? [],
        );
    }
}
