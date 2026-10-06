<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Slack no permite "crear" usuarios vía API pública normal (se invita por
 * email mediante SCIM en planes Enterprise). Este adaptador usa el
 * endpoint SCIM si api_config['scim'] está habilitado; si no, deja la
 * invitación como pendiente y registra el intento.
 */
class SlackService extends BaseSubsystemService implements SubsystemConnectionInterface, UsernameAvailabilityInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        if (empty($subsystem->api_config['scim_habilitado'])) {
            return SubsystemOperationResult::ok(
                mensaje: 'Slack está configurado en modo manual (SCIM no habilitado)',
            );
        }

        $response = $this->http($subsystem)->get('/scim/v1/ServiceProviderConfig');

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                'Slack SCIM no está disponible o rechazó la autenticación (HTTP '.$response->status().')',
            );
        }

        return SubsystemOperationResult::ok(mensaje: 'Conexión y autenticación con Slack SCIM exitosas');
    }

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

        // Deshabilitar, no borrar. El PATCH es el mismo que usa reactivateUser()
        // con 'active' a false, que es lo que getUserStatus() lee para saber si
        // el usuario sigue activo: con un DELETE, la baja (Fase 2.6) era
        // irreversible y había que rehacer el alta si el mismo login volvía.
        $response = $this->http($account->subsystem)->patch('/scim/v1/Users/'.$account->external_account_id, [
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Slack', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    /**
     * Sincroniza el nombre del usuario SCIM.
     *
     * El email es el userName del usuario SCIM — su identidad — así que no se
     * toca. Sin SCIM habilitado no hay nada que actualizar: el mismo criterio
     * que usan disableUser() y reactivateUser() para no llamar a un recurso que
     * no existe.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        if (empty($account->external_account_id)) {
            return SubsystemOperationResult::ok(estado: 'activo', mensaje: 'Sin cuenta SCIM asociada; nada que sincronizar en Slack');
        }

        $response = $this->http($account->subsystem)->patch('/scim/v1/Users/'.$account->external_account_id, [
            'Operations' => [['op' => 'replace', 'path' => 'name', 'value' => ['givenName' => $userData['nombre_completo']]]],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo actualizar el usuario en Slack', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
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

    /**
     * Slack no permite cambiar la contraseña de usuarios vía API pública.
     * La contraseña se gestiona desde el propio Slack o mediante SSO.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('Slack no permite cambiar la contraseña de usuarios vía API');
    }

    /**
     * Solo tiene sentido con SCIM habilitado: sin él, createUser() tampoco
     * escribe nada en Slack (deja la invitación como manual), así que no hay
     * colisión que comprobar y se devuelve null en vez de un `false` que
     * fingiría una comprobación que no se ha hecho.
     *
     * El userName de SCIM es el email (lo que createUser() envía), no el login
     * suelto: por eso el dominio es obligatorio aquí para poder construir el
     * valor que se buscará.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool
    {
        if (empty($subsystem->api_config['scim_habilitado'])) {
            return null;
        }

        $dominio = $subsystem->api_config['dominio'] ?? null;

        if (blank($dominio)) {
            return null;
        }

        $userName = $login.'@'.$dominio;

        try {
            $response = $this->http($subsystem)->get('/scim/v1/Users', [
                'filter' => 'userName eq "'.$userName.'"',
                'count' => 1,
            ]);
        } catch (\Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en Slack', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $resources = $response->json('Resources');

        if (! is_array($resources)) {
            // SCIM respondería con Resources; si no viene, no se sabe qué hay.
            return null;
        }

        return count($resources) > 0;
    }
}
