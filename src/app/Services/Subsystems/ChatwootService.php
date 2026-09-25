<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
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

        $response = $this->http($subsystem)->post("/api/v1/accounts/{$accountId}/agents", [
            'name' => $userData['nombre_completo'],
            'email' => $userData['email_personal'] ?? $userData['usuario'].'@'.($userData['empresa'] ?? 'empresa'),
            'role' => 'agent',
        ]);

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
}
