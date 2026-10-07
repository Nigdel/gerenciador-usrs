<?php

namespace App\Services\Subsystems;

use App\Contracts\IdentityProviderInterface;
use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
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
        $response = $this->request($account->subsystem, 'PATCH', "proprietarios/internos/{$account->external_account_id}/suspender", form: [
            'motivo' => $suspensionData['motivo_suspension'] ?? null,
            'inicio' => ($suspensionData['inicio_suspension'] ?? now())->toIso8601String(),
            'fin' => $suspensionData['fin_suspension']?->toIso8601String(),
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender el propietario en Adagio (endpoint no confirmado)', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido', raw: $response->json() ?? []);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->request($account->subsystem, 'PATCH', "proprietarios/internos/{$account->external_account_id}/reactivar");

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el propietario en Adagio (endpoint no confirmado)', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->request($account->subsystem, 'DELETE', "proprietarios/internos/{$account->external_account_id}");

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el propietario en Adagio (endpoint no confirmado)', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->request($account->subsystem, 'GET', "proprietarios/internos/{$account->external_account_id}");

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Adagio (endpoint no confirmado)', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: $response->json('estado'), raw: $response->json() ?? []);
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
        $nombre = trim((string) ($userData['nombre_completo'] ?? ''));

        if ($nombre === '') {
            return SubsystemOperationResult::fail('Falta nombre_completo para actualizar el propietario en Adagio');
        }

        $response = $this->request(
            $account->subsystem,
            'PUT',
            "proprietarios/internos/{$account->external_account_id}",
            form: ['nome' => $nombre],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo actualizar el propietario en Adagio', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
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
}
