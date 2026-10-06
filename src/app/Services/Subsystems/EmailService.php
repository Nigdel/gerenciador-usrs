<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Gestiona la casilla de correo corporativa (ej. panel de un proveedor de
 * email, cPanel, Google Workspace, Zimbra, etc. según api_config).
 */
class EmailService extends BaseSubsystemService implements SubsystemConnectionInterface, UsernameAvailabilityInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        $response = $this->http($subsystem)->get('/mailboxes', ['limit' => 1]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                'El servicio de email no está disponible o rechazó la autenticación (HTTP '.$response->status().')',
            );
        }

        return SubsystemOperationResult::ok(mensaje: 'Conexión y autenticación con el servicio de email exitosas');
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $direccion = $userData['usuario'].'@'.$this->dominio($subsystem, $userData['empresa'] ?? null);

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
        // Deshabilitar, no borrar. Antes hacía DELETE, con lo que la casilla
        // desaparecía de verdad y la baja (Fase 2.6) era irreversible: el
        // mismo PATCH que usa suspendUser la deja en el sitio y sin acceso.
        $response = $this->http($account->subsystem)->patch('/mailboxes/'.$account->external_account_id, ['active' => false]);

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

    /**
     * Restablece la contraseña de una casilla de correo.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch(
            '/mailboxes/'.$account->external_account_id,
            ['password' => $newPassword],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo restablecer la contraseña de la casilla de correo', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(raw: $response->json() ?? []);
    }

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    /**
     * Solo se actualiza el nombre visible de la casilla. La dirección es la
     * identidad de la cuenta (es como se construyó el login) y los teléfonos no
     * existen como atributos en este driver.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch(
            '/mailboxes/'.$account->external_account_id,
            ['name' => $userData['nombre_completo']],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo actualizar el nombre de la casilla de correo', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    /**
     * El dominio con el que se construiría la casilla. Se extrae a un helper
     * porque ahora lo necesitan tanto createUser() como loginEnUso(): si las
     * dos rutas calcularan el dominio por su cuenta, la comprobación podría
     * mirar en un dominio distinto del que luego se crea la casilla, que es
     * justo el error que esta comprobación existe para evitar.
     */
    private function dominio(Subsystem $subsystem, ?string $empresa): string
    {
        return $subsystem->api_config['dominio'] ?? ($empresa ?? 'empresa').'.com.br';
    }

    /**
     * La dirección del buzón **es** la identidad de la cuenta, igual que en el
     * alta: se consulta el mismo valor que se crearía, no el login suelto.
     *
     * 404 = el buzón no existe = libre; cualquier otro error = null (no
     * comprobable), no false.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool
    {
        $direccion = $login.'@'.$this->dominio($subsystem, $empresa);

        try {
            $response = $this->http($subsystem)->get('/mailboxes/'.rawurlencode($direccion));
        } catch (\Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en el servicio de email', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            return null;
        }

        return true;
    }
}
