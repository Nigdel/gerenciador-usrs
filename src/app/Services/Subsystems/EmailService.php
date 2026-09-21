<?php

namespace App\Services\Subsystems;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Gestiona la casilla de correo corporativa (ej. panel de un proveedor de
 * email, cPanel, Google Workspace, Zimbra, etc. según api_config).
 */
class EmailService extends BaseSubsystemService
{
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $dominio = $subsystem->api_config['dominio'] ?? ($userData['empresa'] ?? 'empresa').'.com.br';
        $direccion = $userData['usuario'].'@'.$dominio;

        $response = $this->http($subsystem)->post('/mailboxes', [
            'address' => $direccion,
            'name' => $userData['nombre_completo'],
            'password' => $userData['password_general'] ?? null,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo crear la casilla de correo', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $direccion,
            externalAccountId: (string) ($response->json('id') ?? $direccion),
            estado: 'activo',
            raw: $response->json() ?? [],
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch(
            '/mailboxes/'.$account->external_account_id,
            ['active' => false, 'reason' => $suspensionData['motivo_suspension'] ?? null],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender la casilla de correo', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido', raw: $response->json() ?? []);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch('/mailboxes/'.$account->external_account_id, ['active' => true]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar la casilla de correo', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->delete('/mailboxes/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar la casilla de correo', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->get('/mailboxes/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado de la casilla', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            estado: $response->json('active') ? 'activo' : 'deshabilitado',
            raw: $response->json() ?? [],
        );
    }
}
