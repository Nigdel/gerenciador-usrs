<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Microsoft Entra ID (Azure AD) vía Microsoft Graph API, multi-tenant.
 *
 * Un único Subsystem administra varios tenants y guarda en api_config.accounts:
 *   tenant_id, client_id, client_secret, dominio -> configuración por empresa
 *   timeout (opcional)                   -> segundos, por defecto 15
 *
 * cpf se mapea a employeeId; el login se construye como userPrincipalName.
 * Nota: PATCH en Graph devuelve 204 sin cuerpo -> no depender de $response->json().
 */
class EntraIdService extends BaseSubsystemService implements SubsystemConnectionInterface
{
    private const GRAPH_URL = 'https://graph.microsoft.com';

    private const LOGIN_URL = 'https://login.microsoftonline.com';

    // ---------------------------------------------------------------
    // Autenticación (client_credentials) y cliente HTTP
    // ---------------------------------------------------------------

    /**
     * Cliente HTTP hacia Graph con el token del tenant del subsistema.
     * Si Graph responde 401 (token vencido o revocado) renueva el token y reintenta una vez.
     */
    protected function http(Subsystem $subsystem, ?string $empresa = null): PendingRequest
    {
        [$config, $cuenta] = $this->configuracionDeEmpresa($subsystem, $empresa);

        return Http::baseUrl(self::GRAPH_URL)
            ->acceptJson()
            ->timeout((int) ($config['timeout'] ?? 15))
            ->withToken($this->accessToken($subsystem, $config, $cuenta))
            ->retry(2, 0, function ($exception, PendingRequest $request) use ($subsystem, $config, $cuenta) {
                if (! $exception instanceof RequestException || $exception->response->status() !== 401) {
                    return false;
                }

                Cache::forget($this->tokenCacheKey($subsystem, $config));
                $request->withToken($this->accessToken($subsystem, $config, $cuenta));

                return true;
            }, throw: false);
    }

    private function accessToken(Subsystem $subsystem, array $config, ?string $cuenta): string
    {
        $key = $this->tokenCacheKey($subsystem, $config);

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $config = $this->requiredConfig($config, $cuenta);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) ($config['timeout'] ?? 15))
            ->post(self::LOGIN_URL.'/'.$config['tenant_id'].'/oauth2/v2.0/token', [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'scope' => self::GRAPH_URL.'/.default',
                'grant_type' => 'client_credentials',
            ]);

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            // No se incluye el cuerpo completo ni el secret en el mensaje.
            throw new RuntimeException(
                'Entra ID no pudo emitir el token (HTTP '.$response->status().': '.$response->json('error', 'sin_detalle').')',
            );
        }

        // Se renueva 60s antes del vencimiento real.
        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    private function tokenCacheKey(Subsystem $subsystem, array $config): string
    {
        return "entraid:token:{$subsystem->id}:".($config['tenant_id'] ?? 'default');
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredConfig(array $config, ?string $cuenta = null): array
    {
        $faltantes = array_filter(
            ['tenant_id', 'client_id', 'client_secret'],
            fn (string $clave) => empty($config[$clave]),
        );

        if ($faltantes !== []) {
            $contexto = $cuenta ? " para la empresa '{$cuenta}'" : '';
            throw new RuntimeException('Falta configuración de Entra ID'.$contexto.': '.implode(', ', $faltantes));
        }

        return $config;
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function configuracionDeEmpresa(Subsystem $subsystem, ?string $empresa): array
    {
        $config = $subsystem->api_config ?? [];
        $cuentas = $config['accounts'] ?? [];

        if ($cuentas === []) {
            return [$config, null];
        }

        $empresaNormalizada = $this->normalizarEmpresa($empresa);

        foreach ($cuentas as $cuenta => $configuracion) {
            if ($this->normalizarEmpresa((string) $cuenta) !== $empresaNormalizada) {
                continue;
            }

            if (! is_array($configuracion)) {
                throw new RuntimeException("La configuración de Entra ID para '{$cuenta}' debe ser un objeto");
            }

            return [array_merge($config, $configuracion), (string) $cuenta];
        }

        throw new RuntimeException(
            "No hay configuración de Entra ID para la empresa '{$empresa}'. Empresas configuradas: ".implode(', ', array_keys($cuentas)),
        );
    }

    private function normalizarEmpresa(?string $empresa): string
    {
        return strtolower(trim((string) $empresa));
    }

    private function mapaCuentas(Subsystem $subsystem): array
    {
        return $subsystem->api_config['accounts'] ?? [];
    }

    // ---------------------------------------------------------------
    // Operaciones
    // ---------------------------------------------------------------

    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        $cuentas = $this->mapaCuentas($subsystem);
        $empresas = $cuentas === [] ? [null] : array_keys($cuentas);
        $resultados = [];

        foreach ($empresas as $empresa) {
            try {
                // /users necesita solo User.ReadWrite.All; /organization exigiría un permiso extra.
                $response = $this->http($subsystem, $empresa)->get('/v1.0/users', [
                    '$top' => 1,
                    '$select' => 'id',
                ]);
            } catch (Throwable $exception) {
                return SubsystemOperationResult::fail($exception->getMessage(), $resultados);
            }

            $resultados[$empresa ?? 'default'] = [
                'ok' => $response->successful(),
                'status' => $response->status(),
            ];

            if ($response->failed()) {
                return SubsystemOperationResult::fail(
                    'Entra ID no está disponible o rechazó la autenticación para '.($empresa ?? 'la configuración principal').' (HTTP '.$response->status().')',
                    $resultados,
                );
            }
        }

        return SubsystemOperationResult::ok(
            mensaje: 'Conexión y autenticación con Entra ID exitosas (todas las cuentas)',
            raw: $resultados,
        );
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $empresa = $userData['empresa'] ?? null;
        [$config] = $this->configuracionDeEmpresa($subsystem, $empresa);
        $dominio = $config['dominio'] ?? null;

        if (! $dominio) {
            return SubsystemOperationResult::fail('Falta api_config.dominio para armar el userPrincipalName');
        }

        $upn = $userData['usuario'].'@'.$dominio;

        // Idempotente: si ya existe un usuario con ese UPN, se reutiliza en vez de duplicar.
        $existente = $this->http($subsystem, $empresa)->get('/v1.0/users/'.rawurlencode($upn), [
            '$select' => 'id,accountEnabled,userPrincipalName',
        ]);

        if ($existente->successful()) {
            return SubsystemOperationResult::ok(
                credencialUsuario: $upn,
                externalAccountId: (string) $existente->json('id'),
                estado: $existente->json('accountEnabled') ? 'activo' : 'deshabilitado',
                raw: $existente->json() ?? [],
            );
        }

        if ($existente->status() !== 404) {
            return SubsystemOperationResult::fail(
                'No se pudo verificar si el usuario ya existe en Entra ID (HTTP '.$existente->status().')',
                $existente->json() ?? [],
            );
        }

        $payload = [
            'accountEnabled' => true,
            'displayName' => $userData['nombre_completo'],
            'mailNickname' => $userData['usuario'],
            'userPrincipalName' => $upn,
            'passwordProfile' => [
                'forceChangePasswordNextSignIn' => true,
                'password' => $userData['password_general'] ?? null,
            ],
        ];

        if (! empty($userData['cpf'])) {
            $payload['employeeId'] = $userData['cpf'];
        }

        $response = $this->http($subsystem, $empresa)->post('/v1.0/users', $payload);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Entra ID rechazó la creación del usuario', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $upn,
            externalAccountId: (string) $response->json('id'),
            estado: 'activo',
            raw: $response->json() ?? [],
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => false,
        ]);

        // PATCH exitoso en Graph devuelve 204 sin body.
        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido');
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => true,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo');
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => false,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado');
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)
            ->delete('/v1.0/users/'.rawurlencode((string) $account->external_account_id));

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo eliminar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'eliminado');
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->get('/v1.0/users/'.$account->external_account_id, [
            '$select' => 'accountEnabled',
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            estado: $response->json('accountEnabled') ? 'activo' : 'deshabilitado',
            raw: $response->json() ?? [],
        );
    }

    /**
     * Restablece la contraseña de un usuario en Entra ID.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem, $account->user->empresa ?? null)->patch('/v1.0/users/'.$account->external_account_id, [
            'passwordProfile' => [
                'forceChangePasswordNextSignIn' => true,
                'password' => $newPassword,
            ],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo restablecer la contraseña en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo');
    }
}
