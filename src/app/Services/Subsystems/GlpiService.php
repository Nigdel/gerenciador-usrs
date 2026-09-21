<?php

namespace App\Services\Subsystems;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

class GlpiService extends BaseSubsystemService
{
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        // Idempotente: si ya existe un login igual en GLPI, se reutiliza en vez de duplicar.
        $existente = $this->http($subsystem)->get('/User', ['searchText' => $userData['usuario']]);

        if ($existente->successful() && ! empty($existente->json('data.0.id'))) {
            return SubsystemOperationResult::ok(
                credencialUsuario: $userData['usuario'],
                externalAccountId: (string) $existente->json('data.0.id'),
                estado: 'activo',
                mensaje: 'Usuario ya existía en GLPI, se reutilizó',
                raw: $existente->json() ?? [],
            );
        }

        $response = $this->http($subsystem)->post('/User', [
            'input' => [
                'name' => $userData['usuario'],
                'realname' => $userData['nombre_completo'],
                '_useremails' => [$userData['email_personal'] ?? null],
                'password' => $userData['password_general'] ?? null,
                'password2' => $userData['password_general'] ?? null,
            ],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('GLPI rechazó la creación del usuario', $response->json() ?? []);
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
        $response = $this->http($account->subsystem)->put('/User/'.$account->external_account_id, [
            'input' => ['is_active' => false],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender el usuario en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido', raw: $response->json() ?? []);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->put('/User/'.$account->external_account_id, [
            'input' => ['is_active' => true],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->delete('/User/'.$account->external_account_id, [
            'input' => ['id' => $account->external_account_id],
            'force_purge' => false,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->get('/User/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en GLPI', $response->json() ?? []);
        }

        $activo = (bool) $response->json('is_active');

        return SubsystemOperationResult::ok(estado: $activo ? 'activo' : 'deshabilitado', raw: $response->json() ?? []);
    }
}
