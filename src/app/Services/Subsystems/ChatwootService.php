<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chatwoot: se gerencian 2 cuentas distintas dentro de la misma instancia
 * (Klios y Federal), cada una con su propio account_id. El mapeo
 * empresa -> account_id vive en api_config.accounts del subsistema:
 *
 *   'accounts' => ['klios' => 1, 'federal' => 2]
 *
 * En creación se resuelve por $userData['empresa']; en las operaciones
 * posteriores (suspender/reactivar/deshabilitar/estado), como solo se
 * recibe la cuenta (UserSubsystemAccount), se resuelve por la empresa del
 * GestorUser dueño de esa cuenta ($account->user->empresa) — así no hace
 * falta guardar el account_id por separado en cada fila.
 */
class ChatwootService extends BaseSubsystemService implements SubsystemConnectionInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        $accounts = $this->mapaCuentas($subsystem);
        if (empty($accounts)) {
            return SubsystemOperationResult::fail('Falta api_config.accounts del subsistema Chatwoot');
        }

        $resultados = [];
        $fallo = false;

        foreach ($accounts as $empresa => $accountId) {
            $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}");

            $ok = $response->successful();
            $fallo = $fallo || ! $ok;

            $resultados[$empresa] = [
                'account_id' => $accountId,
                'ok' => $ok,
                'status' => $response->status(),
            ];
        }

        if ($fallo) {
            return SubsystemOperationResult::fail('Una o más cuentas de Chatwoot fallaron la verificación', $resultados);
        }

        return SubsystemOperationResult::ok(mensaje: 'Conexión y autenticación con Chatwoot exitosas (todas las cuentas)', raw: $resultados);
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountId($subsystem, $userData['empresa'] ?? null);
        $payload = [
            'name' => $userData['nombre_completo'],
            'email' => $userData['email_personal'] ?? $userData['usuario'].'@'.($userData['empresa'] ?? 'empresa'),
            'role' => 'agent',
        ];

        $teamIds = $this->resolverTeamIds($userData, $subsystem);
        if (! empty($teamIds)) {
            $payload['team_ids'] = $teamIds;
        }

        $response = $this->http($subsystem)->post("/api/v1/accounts/{$accountId}/agents", $payload);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Chatwoot rechazó la creación del agente', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $userData['usuario'],
            externalAccountId: (string) $response->json('id'),
            estado: 'activo',
            raw: array_merge($response->json() ?? [], ['account_id' => $accountId]),
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return $this->disableUser($account); // Chatwoot no distingue suspendido de deshabilitado a nivel de agente
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        $response = $this->http($account->subsystem)->patch(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
            ['availability' => 'online'],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el agente en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        $response = $this->http($account->subsystem)->delete(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el agente en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $this->resolverAccountIdDeCuenta($account);

        $response = $this->http($account->subsystem)->get(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: $response->json('availability') === 'online' ? 'activo' : 'deshabilitado', raw: $response->json() ?? []);
    }

    /**
     * @return array<int, int>
     */
    private function resolverTeamIds(array $userData, Subsystem $subsystem): array
    {
        $selectedTeams = $userData['subsystem_config']['chatwoot']['teams']
            ?? $userData['subsystem_config'][$subsystem->slug]['teams']
            ?? $userData['teams']
            ?? $userData['team_ids']
            ?? [];

        if (! is_array($selectedTeams)) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(static fn ($teamId) => (int) $teamId, $selectedTeams)));

        return array_values(array_filter($normalized, static fn (int $teamId): bool => $teamId > 0));
    }

    /**
     * @return array<int, array{id: mixed, name: string}>
     */
    public function listTeams(Subsystem $subsystem, ?string $empresa): array
    {
        $accountId = $this->resolverAccountId($subsystem, $empresa);

        $response = $this->http($subsystem)->get("/api/v1/accounts/{$accountId}/teams");

        if ($response->failed()) {
            throw new RuntimeException("No se pudo consultar los equipos de Chatwoot para la empresa '{$empresa}'.");
        }

        $payload = $response->json();
        $items = $payload['payload'] ?? $payload['data'] ?? $payload ?? [];

        if (! is_array($items)) {
            return [];
        }

        $teams = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['team_id'] ?? null;
            $name = $item['name'] ?? $item['title'] ?? $item['label'] ?? null;

            if ($id === null || $name === null) {
                continue;
            }

            $teams[] = ['id' => $id, 'name' => (string) $name];
        }

        return $teams;
    }

    // -----------------------------------------------------------------
    // Resolución de cuenta (account_id) según empresa
    // -----------------------------------------------------------------

    /**
     * @return array<string, int|string> ej. ['klios' => 1, 'federal' => 2]
     */
    private function mapaCuentas(Subsystem $subsystem): array
    {
        return $subsystem->api_config['accounts'] ?? [];
    }

    private function resolverAccountId(Subsystem $subsystem, ?string $empresa): int|string
    {
        $clave = strtolower((string) $empresa);
        $accounts = $this->mapaCuentas($subsystem);

        if (! isset($accounts[$clave])) {
            throw new RuntimeException(
                "[Chatwoot] No hay account_id configurado para la empresa '{$empresa}'. "
                .'Configura api_config.accounts en el subsistema, ej.: {"klios": 1, "federal": 2}. '
                .'Empresas configuradas: '.implode(', ', array_keys($accounts)),
            );
        }

        return $accounts[$clave];
    }

    /**
     * Para operaciones sobre una cuenta ya existente (suspender, reactivar,
     * deshabilitar, estado), la empresa se toma del GestorUser dueño de la
     * cuenta, no del payload de la request.
     */
    private function resolverAccountIdDeCuenta(UserSubsystemAccount $account): int|string
    {
        return $this->resolverAccountId($account->subsystem, $account->user->empresa ?? null);
    }

    /**
     * Fija una nueva contraseña para el usuario en Chatwoot (Platform API).
     *
     * Requiere `platform_token` en api_config y que la Platform App tenga permiso sobre el usuario.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        if (blank($account->external_account_id)) {
            return SubsystemOperationResult::fail('La cuenta no tiene un ID externo de usuario en Chatwoot');
        }

        try {
            $response = $this->platformHttp($account->subsystem)->patch(
                "/platform/api/v1/users/{$account->external_account_id}",
                ['password' => $newPassword],
            );
        } catch (\Throwable $e) {
            report($e);

            return SubsystemOperationResult::fail('Error de comunicación con Chatwoot al cambiar la contraseña');
        }

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                match ($response->status()) {
                    401 => 'Token de Platform App inválido en Chatwoot',
                    403 => 'La Platform App no tiene permiso sobre este usuario en Chatwoot',
                    404 => 'El usuario no existe en Chatwoot',
                    422 => 'Chatwoot rechazó la contraseña (no cumple su política)',
                    default => 'No se pudo cambiar la contraseña del usuario en Chatwoot',
                },
                $this->sanitizeRaw($response->json() ?? []),
            );
        }

        return SubsystemOperationResult::ok(
            estado: $account->estado->value,
            raw: $this->sanitizeRaw($response->json() ?? []),
        );
    }

    private function sanitizeRaw(array $raw): array
    {
        unset($raw['access_token'], $raw['password']);

        return $raw;
    }

    /**
     * Cliente HTTP para la Platform API de Chatwoot (/platform/api/v1/...).
     * Usa `platform_token`, distinto del token de agente que usa http().
     */
    protected function platformHttp(Subsystem $subsystem): PendingRequest
    {
        $config = $subsystem->api_config ?? [];
        $baseUrl = rtrim((string) $subsystem->api_url, '/');

        if ($baseUrl === '') {
            throw new RuntimeException("El subsistema {$subsystem->getKey()} no tiene api_url configurada");
        }

        if (blank($config['platform_token'] ?? null)) {
            throw new RuntimeException("El subsistema {$subsystem->getKey()} no tiene platform_token configurado");
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->connectTimeout((int) ($config['connect_timeout'] ?? 5))
            ->timeout((int) ($config['timeout'] ?? 15))
            ->withHeaders(['api_access_token' => $config['platform_token']]);
    }
}
