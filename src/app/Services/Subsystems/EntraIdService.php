<?php

namespace App\Services\Subsystems;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Microsoft Entra ID (Azure AD) vía Microsoft Graph API.
 * cpf se mapea a employeeId; el login se construye como userPrincipalName
 * dentro del dominio configurado en api_config['dominio'].
 * Nota: PATCH en Graph devuelve 204 sin cuerpo -> no depender de $response->json().
 */
class EntraIdService extends BaseSubsystemService
{
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $dominio = $subsystem->api_config['dominio'] ?? ($userData['empresa'] ?? 'empresa').'.onmicrosoft.com';
        $upn = $userData['usuario'].'@'.$dominio;

        $response = $this->http($subsystem)->post('/v1.0/users', [
            'accountEnabled' => true,
            'displayName' => $userData['nombre_completo'],
            'employeeId' => $userData['cpf'] ?? null,
            'mailNickname' => $userData['usuario'],
            'userPrincipalName' => $upn,
            'passwordProfile' => [
                'forceChangePasswordNextSignIn' => true,
                'password' => $userData['password_general'] ?? null,
            ],
        ]);

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
        $response = $this->http($account->subsystem)->patch('/v1.0/users/'.$account->external_account_id, [
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
        $response = $this->http($account->subsystem)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => true,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo');
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch('/v1.0/users/'.$account->external_account_id, [
            'accountEnabled' => false,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Entra ID', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado');
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->get('/v1.0/users/'.$account->external_account_id, [
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
}
