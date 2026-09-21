<?php

namespace App\Services\Subsystems;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Samba AD, normalmente expuesto vía un microservicio propio que envuelve
 * `samba-tool` o LDAP, ya que no tiene una API REST nativa. Este adaptador
 * asume un wrapper REST configurado en api_url/api_config.
 */
class SambaAdService extends BaseSubsystemService
{
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $response = $this->http($subsystem)->post('/users', [
            'sAMAccountName' => $userData['usuario'],
            'displayName' => $userData['nombre_completo'],
            'password' => $userData['password_general'] ?? null,
            'ou' => $subsystem->api_config['ou'] ?? null,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Samba AD rechazó la creación del usuario', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $userData['usuario'],
            externalAccountId: (string) ($response->json('dn') ?? $userData['usuario']),
            estado: 'activo',
            raw: $response->json() ?? [],
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->post('/users/'.$account->external_account_id.'/disable');

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender el usuario en Samba AD', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido', raw: $response->json() ?? []);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->post('/users/'.$account->external_account_id.'/enable');

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en Samba AD', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->post('/users/'.$account->external_account_id.'/disable');

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Samba AD', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->get('/users/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Samba AD', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            estado: $response->json('enabled') ? 'activo' : 'deshabilitado',
            raw: $response->json() ?? [],
        );
    }
}
