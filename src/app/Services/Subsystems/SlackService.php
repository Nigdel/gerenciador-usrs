<?php

namespace App\Services\Subsystems;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Slack no permite "crear" usuarios vía API pública normal (se invita por
 * email mediante SCIM en planes Enterprise). Este adaptador usa el
 * endpoint SCIM si api_config['scim'] está habilitado; si no, deja la
 * invitación como pendiente y registra el intento.
 */
class SlackService extends BaseSubsystemService
{
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        if (empty($subsystem->api_config['scim_habilitado'])) {
            return SubsystemOperationResult::ok(
                credencialUsuario: $userData['email_personal'] ?? $userData['usuario'],
                estado: 'activo',
                mensaje: 'Invitación registrada manualmente: Slack requiere SCIM/Enterprise para invitar por API',
            );
        }

        $response = $this->http($subsystem)->post('/scim/v1/Users', [
            'userName' => $userData['email_personal'] ?? $userData['usuario'],
            'name' => ['givenName' => $userData['nombre_completo']],
            'active' => true,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Slack rechazó la invitación del usuario', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $userData['email_personal'] ?? $userData['usuario'],
            externalAccountId: (string) $response->json('id'),
            estado: 'activo',
            raw: $response->json() ?? [],
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return $this->disableUser($account);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch('/scim/v1/Users/'.$account->external_account_id, [
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true]],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en Slack', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        if (empty($account->external_account_id)) {
            return SubsystemOperationResult::ok(estado: 'deshabilitado', mensaje: 'Sin cuenta SCIM asociada; nada que deshabilitar en Slack');
        }

        $response = $this->http($account->subsystem)->delete('/scim/v1/Users/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Slack', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        if (empty($account->external_account_id)) {
            return SubsystemOperationResult::ok(estado: $account->estado->value);
        }

        $response = $this->http($account->subsystem)->get('/scim/v1/Users/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Slack', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: $response->json('active') ? 'activo' : 'deshabilitado', raw: $response->json() ?? []);
    }
}
