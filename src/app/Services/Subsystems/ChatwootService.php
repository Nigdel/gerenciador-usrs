<?php

namespace App\Services\Subsystems;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

class ChatwootService extends BaseSubsystemService
{
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $accountId = $subsystem->api_config['account_id'] ?? null;

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
            raw: $response->json() ?? [],
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return $this->disableUser($account); // Chatwoot no distingue suspendido de deshabilitado a nivel de agente
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $accountId = $account->subsystem->api_config['account_id'] ?? null;

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
        $accountId = $account->subsystem->api_config['account_id'] ?? null;

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
        $accountId = $account->subsystem->api_config['account_id'] ?? null;

        $response = $this->http($account->subsystem)->get(
            "/api/v1/accounts/{$accountId}/agents/{$account->external_account_id}",
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Chatwoot', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: $response->json('availability') === 'online' ? 'activo' : 'deshabilitado', raw: $response->json() ?? []);
    }
}
