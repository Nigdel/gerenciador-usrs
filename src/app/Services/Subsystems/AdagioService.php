<?php

namespace App\Services\Subsystems;

use App\Contracts\IdentityProviderInterface;
use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
use App\Enums\GestorUserStatus;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adagio (Klios) — proveedor de identidad del Gestor.
 *
 * Réplica fiel de la integración real en Python (AdagioService/BaseService):
 *  - Autenticación por sesión JWT: POST {base_url}/kliosAnalise/login con
 *    email/password como multipart/form-data (no JSON), devuelve `token` +
 *    `UserId`. El token se cachea y se reintenta el login una sola vez si
 *    una request responde 401/403.
 *  - Todas las llamadas de escritura van como multipart/form-data (igual
 *    que `requests.request(..., files=...)` en Python), no como JSON.
 *  - Búsqueda de propietario interno: GET /proprietarios/internos con
 *    `documento` (CPF limpio, solo dígitos) o `email` como query param.
 *  - Creación: POST /proprietarios/internos con contexto_id, nome,
 *    documento, email. Adagio genera la contraseña automáticamente
 *    (no se envía password_general); viene en la respuesta.
 *  - `contexto_id` se resuelve a partir de una "entidad" (klios=64,
 *    federal=72) — ver ENTIDAD_CONTEXTO. Debe llegar en $userData['entidad']
 *    (o, si no, en $subsystem->api_config['entidad_default']).
 *
 * Pendiente de confirmar contigo: los endpoints reales de suspender /
 * reactivar / deshabilitar / consultar estado — el código Python
 * compartido solo cubre login + creación. Los de abajo son un patrón
 * provisional sobre /proprietarios/internos/{id}
 */
class AdagioService extends BaseSubsystemService implements IdentityProviderInterface, SubsystemConnectionInterface, UsernameAvailabilityInterface
{
    private const ENTIDAD_CONTEXTO = [
        'klios' => '64',
        'federal' => '72',
    ];

    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        Cache::forget($this->tokenCacheKey($subsystem));

        try {
            $this->token($subsystem);
        } catch (RuntimeException $exception) {
            return SubsystemOperationResult::fail($exception->getMessage());
        }

        return SubsystemOperationResult::ok(mensaje: 'Autenticación contra Adagio exitosa');
    }

    public function findByCpf(string $cpf): ?array
    {
        $subsystem = $this->resolveSubsystem();
        $documento = $this->limpiarDocumento($cpf);

        $response = $this->request($subsystem, 'GET', 'proprietarios/internos', query: ['documento' => $documento]);

        return $this->mapearPropietario($response, $cpf);
    }

    public function existsByEmail(string $email): bool
    {
        $subsystem = $this->resolveSubsystem();

        $response = $this->request($subsystem, 'GET', 'proprietarios/internos', query: ['email' => trim(strtolower($email))]);

        if ($response->failed()) {
            return false;
        }

        $usuario = $this->extraerUsuario($response);

        return ! empty($usuario['id']) || ! empty($usuario['email']);
    }

    /**
     * En Adagio el email ES la credencial (no hay un campo "login" aparte), así
     * que comprobar el login es comprobar el email con el dominio del
     * subsistema.
     *
     * A diferencia de existsByEmail() —que es una búsqueda de usuario y por eso
     * devuelve false si falla—, aquí un fallo se devuelve como **null**
     * ("no se pudo comprobar"). Ver UsernameAvailabilityInterface: leer un 500
     * como "login libre" haría proponer al generador un login ya ocupado.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool
    {
        $dominio = $subsystem->api_config['dominio'] ?? null;

        if (blank($dominio)) {
            // Sin dominio no hay forma de armar el email que Adagio usa como
            // credencial. No se inventa: se declara no comprobable.
            return null;
        }

        $email = Str::of($login)->lower()->ascii()->replace(' ', '')->toString().'@'.$dominio;

        try {
            $response = $this->request($subsystem, 'GET', 'proprietarios/internos', query: ['email' => $email]);
            // Throwable y no RuntimeException: la caída de red de Adagio llega
            // como HttpClient\ConnectionException, que hereda de
            // HttpClientException y no de RuntimeException, así que con
            // RuntimeException se escapaba hacia el controlador en vez de
            // devolverse el null de «no comprobable» que promete el contrato.
        } catch (\Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en Adagio', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        // Un 404 significa "no hay propietario con ese email", no un fallo de
        // Adagio: tratarlo como no comprobable haría que NINGÚN login llegara
        // nunca a proponerse y el generador agotaría los 100 intentos.
        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            return null;
        }

        $usuario = $this->extraerUsuario($response);

        return ! empty($usuario['id']) || ! empty($usuario['email']);
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $documento = $this->limpiarDocumento($userData['cpf'] ?? '');

        // Idempotente: si ya existe un propietario con ese documento, se reutiliza.
        $existente = $this->mapearPropietarioRaw(
            $this->request($subsystem, 'GET', 'proprietarios/internos', query: ['documento' => $documento]),
        );

        if (! empty($existente['id'])) {
            return SubsystemOperationResult::ok(
                credencialUsuario: $existente['email'] ?? $userData['usuario'],
                externalAccountId: (string) $existente['id'],
                estado: 'activo',
                mensaje: 'Usuario ya existía en Adagio, se reutilizó',
                raw: $existente,
            );
        }

        $dominio = $subsystem->api_config['dominio'] ?? null;
        $email = $dominio ? $userData['usuario'].'@'.$dominio : ($userData['email_personal'] ?? null);

        if (! $email) {
            return SubsystemOperationResult::fail(
                'No se pudo determinar el email a crear en Adagio: configura api_config.dominio del subsistema o email_personal del usuario',
            );
        }

        $response = $this->request($subsystem, 'POST', 'proprietarios/internos', form: [
            'contexto_id' => $this->resolverContextoId($userData, $subsystem),
            'nome' => $userData['nombre_completo'],
            'documento' => $documento,
            'email' => $email,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Adagio rechazó la creación del propietario', $response->json() ?? []);
        }

        $creado = $response->json('usuario') ?? $response->json() ?? [];

        return SubsystemOperationResult::ok(
            credencialUsuario: $creado['email'] ?? $email,
            externalAccountId: isset($creado['id']) ? (string) $creado['id'] : null,
            estado: 'activo',
            // La contraseña autogenerada por Adagio viaja en $raw['password'];
            // el llamador decide qué hacer con ella (ej. notificar al usuario).
            raw: $creado,
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return $this->cambiarEstado($account, $suspensionData, activar: false);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return $this->cambiarEstado($account, [], activar: true);
    }

    private function cambiarEstado(UserSubsystemAccount $account, array $data, bool $activar): SubsystemOperationResult
    {
        $documento = $this->limpiarDocumento((string) ($data['cpf'] ?? $account->user?->cpf ?? ''));

        if ($documento === '') {
            return SubsystemOperationResult::fail('Falta el CPF para cambiar el estado del propietario en Adagio');
        }

        try {
            // alterarStatus exige que documento Y email existan a la vez (404 si
            // no): la credencial guardada puede venir sin @dominio, así que el
            // email se resuelve antes de llamar.
            $email = $this->resolverEmail($account, $documento);

            if ($email === null) {
                return SubsystemOperationResult::fail(
                    'No se pudo determinar el email del propietario en Adagio: la credencial no incluye dominio y api_config.dominio no está configurado',
                );
            }

            $response = $this->request($account->subsystem, 'POST', 'proprietarios/internos/alterarStatus', form: [
                'documento' => $documento,
                'email' => $email,
                'status' => $activar ? 1 : 0,
            ]);
        } catch (\Throwable $exception) {
            $this->log('Error de comunicación al cambiar estado en Adagio', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);

            return SubsystemOperationResult::fail('Error de comunicación con Adagio: '.$exception->getMessage());
        }

        if ($response->successful()) {
            $status = $response->json('status');

            if ($status === 'Opção não permitida') {
                return SubsystemOperationResult::fail('Adagio: Opção não permitida', $response->json() ?? []);
            }

            $estadoInterno = match ($status) {
                'Usuário ativado' => 'activo',
                'Usuário desativado' => 'suspendido',
                default => $activar ? 'activo' : 'suspendido',
            };

            return SubsystemOperationResult::ok(
                estado: $estadoInterno,
                mensaje: $status,
                raw: $response->json() ?? [],
            );
        }

        // 403 = "el usuario ya está en el estado pedido" (no está desactivado al
        // activar, o ya lo está al desactivar). Es el estado que se quería, así
        // que se confirma contra Adagio y, si coincide, se devuelve como éxito
        // idempotente. Si la consulta falla, el 403 era otra cosa y se informa.
        if ($response->status() === 403) {
            $remoto = $this->getUserStatus($account);
            $deseado = $activar ? 'activo' : 'suspendido';

            if ($remoto->success && $remoto->estado === $deseado) {
                return SubsystemOperationResult::ok(
                    estado: $deseado,
                    mensaje: $activar
                        ? 'El usuario ya estaba activo en Adagio'
                        : 'El usuario ya estaba desactivado en Adagio',
                );
            }
        }

        $mensaje = match ($response->status()) {
            404 => 'Usuario no encontrado en Adagio (documento y email deben existir ambos)',
            403 => $activar
                ? 'Adagio no pudo activar: el usuario no está desactivado'
                : 'Adagio no pudo desactivar: el usuario ya está desactivado',
            default => "Adagio respondió HTTP {$response->status()}",
        };

        $this->log('Adagio rechazó el cambio de estado', [
            'account_id' => $account->id,
            'http_status' => $response->status(),
            'body' => $response->body(),
        ]);

        return SubsystemOperationResult::fail($mensaje, [
            'http_status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]);
    }
    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return $this->cambiarEstado($account, [], activar: false);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        // La relación del modelo se llama user(); gestorUser no existe y
        // devolvía siempre null, de ahí el "Falta el CPF" en cada confirmación.
        $documento = $this->limpiarDocumento((string) ($account->user?->cpf ?? ''));

        if ($documento === '') {
            return SubsystemOperationResult::fail('Falta el CPF para consultar el estado en Adagio');
        }

        try {
            $response = $this->request($account->subsystem, 'GET', 'proprietarios/internos', query: ['documento' => $documento]);
        } catch (\Throwable $exception) {
            return SubsystemOperationResult::fail('Error de comunicación con Adagio: '.$exception->getMessage());
        }

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                "Adagio respondió HTTP {$response->status()} al consultar el estado",
                $response->json() ?? [],
            );
        }

        $usuario = $this->extraerUsuario($response);

        if (empty($usuario)) {
            return SubsystemOperationResult::ok(
                estado: 'suspendido',
                mensaje: 'Usuario no encontrado en Adagio',
                raw: $response->json() ?? [],
            );
        }

        // El estado se determina por el campo deleted_at:
        // null = activo, fecha = desactivado.
        $estado = isset($usuario['deleted_at']) && $usuario['deleted_at'] !== null
            ? 'suspendido'
            : 'activo';

        return SubsystemOperationResult::ok(
            externalAccountId: isset($usuario['id']) ? (string) $usuario['id'] : null,
            estado: $estado,
            raw: $usuario,
        );
    }

    // -----------------------------------------------------------------
    // Autenticación JWT (login + cache + reintento único en 401/403)
    // -----------------------------------------------------------------

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    /**
     * Actualiza los datos de contacto del propietario interno.
     *
     * El email es aquí la credencial del usuario (se construye como
     * usuario@dominio), así que NO se sincroniza: cambiarlo sería cambiarle el
     * login. Solo el nombre.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        $gestorUser = $account->user;

        if ($gestorUser === null) {
            return SubsystemOperationResult::fail('La cuenta no tiene un usuario del gestor asociado para sincronizar con Adagio');
        }

        $statusResult = $this->getUserStatus($account);

        // Antes un fallo de consulta se devolvía como "no se requiere
        // actualización", ocultando el error. Ahora se propaga.
        if (! $statusResult->success) {
            return $statusResult;
        }

        // estado es un enum (GestorUserStatus), no un string. Solo 'activo' del
        // gestor debe dejar activo el propietario; suspendido y baja lo desactivan.
        $estadoDeseado = $gestorUser->estado === GestorUserStatus::Activo ? 'activo' : 'suspendido';

        if ($statusResult->estado === $estadoDeseado) {
            return SubsystemOperationResult::ok(
                estado: $estadoDeseado,
                mensaje: 'No se requiere actualización de usuario: estado del subsistema y estado del gestor coinciden',
            );
        }

        return $this->cambiarEstado($account, [], activar: $estadoDeseado === 'activo');
    }

    /**
     * Email del propietario tal como Adagio lo tiene registrado.
     *
     * Orden: lo que devuelva Adagio para el documento (autoritativo); si no
     * devuelve nada —un propietario desactivado puede no aparecer en el
     * listado—, la credencial guardada si ya es un email; y como último
     * recurso credencial@dominio. Null si no hay forma de armarlo.
     */
    private function resolverEmail(UserSubsystemAccount $account, string $documento): ?string
    {
        $response = $this->request($account->subsystem, 'GET', 'proprietarios/internos', query: ['documento' => $documento]);
        $usuario = $this->extraerUsuario($response);

        if (! blank($usuario['email'] ?? null)) {
            return (string) $usuario['email'];
        }

        $credencial = trim((string) $account->credencial_usuario);

        if ($credencial === '') {
            return null;
        }

        if (str_contains($credencial, '@')) {
            return $credencial;
        }

        $dominio = $account->subsystem->api_config['dominio'] ?? null;

        return blank($dominio) ? null : $credencial.'@'.$dominio;
    }

    private function request(
        Subsystem $subsystem,
        string $method,
        string $endpoint,
        ?array $form = null,
        ?array $query = null,
    ): Response {
        $response = $this->send($subsystem, $method, $endpoint, $form, $query, $this->token($subsystem));

        if (in_array($response->status(), [401, 403], true)) {
            // Token vencido o inválido: reautenticar una sola vez y reintentar.
            Cache::forget($this->tokenCacheKey($subsystem));
            $response = $this->send($subsystem, $method, $endpoint, $form, $query, $this->token($subsystem));
        }

        return $response;
    }

    private function send(Subsystem $subsystem, string $method, string $endpoint, ?array $form, ?array $query, string $token): Response
    {
        $client = $this->cliente($subsystem, $token);

        if ($form !== null) {
            foreach (array_filter($form, fn ($v) => $v !== null) as $nombre => $valor) {
                $client = $client->attach($nombre, (string) $valor);
            }
        }

        $url = trim($endpoint, '/');

        return match (strtoupper($method)) {
            'GET' => $client->get($url, $query ?? []),
            'POST' => $client->post($url),
            // PUT y no PATCH: es lo que manda updateUser(). Faltaba en el match
            // y el `default` lo convertía en RuntimeException, así que la
            // actualización del propietario en Adagio nunca funcionó — siempre
            // 502. El Sprint 3.3 lo cubren los tests de Adagio\UpdateUserTest.
            'PUT' => $client->put($url),
            'PATCH' => $client->patch($url),
            'DELETE' => $client->delete($url),
            default => throw new RuntimeException("Método HTTP no soportado por AdagioService: {$method}"),
        };
    }

    private function cliente(Subsystem $subsystem, string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $subsystem->api_url, '/').'/')
            ->timeout($subsystem->api_config['timeout'] ?? 30)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Cache-Control' => 'no-cache, private',
            ]);
    }

    private function token(Subsystem $subsystem): string
    {
        return Cache::remember($this->tokenCacheKey($subsystem), now()->addMinutes(15), function () use ($subsystem) {
            return $this->login($subsystem);
        });
    }

    private function login(Subsystem $subsystem): string
    {
        $email = $subsystem->api_config['email'] ?? null;
        $password = $subsystem->api_config['password'] ?? null;

        if (! $email || ! $password) {
            throw new RuntimeException('Falta api_config.email / api_config.password del subsistema Adagio');
        }

        $response = Http::baseUrl(rtrim((string) $subsystem->api_url, '/').'/')
            ->timeout($subsystem->api_config['timeout'] ?? 30)
            ->withHeaders(['Cache-Control' => 'no-cache, private'])
            ->attach('email', $email)
            ->attach('password', $password)
            ->post('kliosAnalise/login');

        if ($response->failed() || empty($response->json('token'))) {
            throw new RuntimeException('[Adagio] No se pudo autenticar: '.$response->body());
        }

        return $response->json('token');
    }

    private function tokenCacheKey(Subsystem $subsystem): string
    {
        return "adagio:token:{$subsystem->id}";
    }

    // -----------------------------------------------------------------
    // Helpers de datos
    // -----------------------------------------------------------------

    private function mapearPropietario(Response $response, string $cpfOriginal): ?array
    {
        $usuario = $this->extraerUsuario($response);

        if (empty($usuario['id']) && empty($usuario['email'])) {
            return null;
        }

        return [
            'nombre_completo' => $usuario['nome'] ?? null,
            'email_personal' => $usuario['email'] ?? null,
            'cpf' => $usuario['documento'] ?? $cpfOriginal,
            // Adagio no tiene un campo "login" separado: el email ES la
            // credencial. Se normaliza a la parte local para que "usuario"
            // sea consistente con el resto del sistema (sin @dominio).
            'usuario' => isset($usuario['email']) ? (strstr($usuario['email'], '@', true) ?: $usuario['email']) : null,
            'external_account_id' => isset($usuario['id']) ? (string) $usuario['id'] : null,
        ];
    }

    private function mapearPropietarioRaw(Response $response): array
    {
        return $this->extraerUsuario($response);
    }

    private function extraerUsuario(Response $response): array
    {
        if ($response->failed()) {
            return [];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        // Adagio a veces anida el registro bajo "usuario", a veces lo devuelve plano.
        return is_array($body['usuario'] ?? null) ? $body['usuario'] : $body;
    }

    private function limpiarDocumento(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?? '';
    }

    private function resolverContextoId(array $userData, Subsystem $subsystem): string
    {
        $entidad = strtolower((string) ($userData['entidad'] ?? $subsystem->api_config['entidad_default'] ?? ''));

        if (! isset(self::ENTIDAD_CONTEXTO[$entidad])) {
            throw new RuntimeException(
                "[Adagio] entidad inválida o no informada: '{$entidad}'. Valores permitidos: "
                .implode(', ', array_keys(self::ENTIDAD_CONTEXTO)),
            );
        }

        return self::ENTIDAD_CONTEXTO[$entidad];
    }

    private function resolveSubsystem(): Subsystem
    {
        return Subsystem::proveedorIdentidad()->activos()->firstOrFail();
    }

    /**
     * Reinicia la contraseña de un usuario en Adagio.
     *
     * @param  string  $newPassword  //unsupported, Adagio generates e link and send it by email to user
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        $baseUrl = rtrim((string) $account->subsystem->api_url, '/');

        // ADAGIO_BASE_URL termina en /api,
        // pero el endpoint de reset está fuera de /api.
        $baseUrl = preg_replace('/\/api$/', '', $baseUrl);

        $response = Http::timeout(
            $account->subsystem->api_config['timeout'] ?? 30
        )->post($baseUrl.'/password/email', [
            'email' => $account->credencial_usuario,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                'Adagio rechazó la solicitud de reset de contraseña',
                $response->json() ?? []
            );
        }

        return SubsystemOperationResult::ok(
            mensaje: 'Solicitud de reset de contraseña enviada a Adagio',
            raw: $response->json() ?? []
        );
    }

    // public function verificaEstadoRemoto(): bool
    // {
    //     // alterarStatus ya confirma con 200 + {"status": "Usuário desativado"}
    //     // y no hay endpoint documentado para leer el estado.
    //     return false;
    // }
}