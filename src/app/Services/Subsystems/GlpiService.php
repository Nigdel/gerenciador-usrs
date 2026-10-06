<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\Contracts\UsernameAvailabilityInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GlpiService extends BaseSubsystemService implements SubsystemConnectionInterface, UsernameAvailabilityInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        Cache::forget($this->sessionCacheKey($subsystem));

        try {
            $response = $this->http($subsystem)->get('/User', ['range' => '0-0']);
        } catch (RuntimeException $exception) {
            return SubsystemOperationResult::fail($exception->getMessage());
        }

        if ($response->failed()) {
            return SubsystemOperationResult::fail(
                'GLPI no está disponible o rechazó la autenticación (HTTP '.$response->status().')',
            );
        }

        return SubsystemOperationResult::ok(mensaje: 'Conexión y autenticación con GLPI exitosas');
    }

    protected function http(Subsystem $subsystem): PendingRequest
    {
        $config = $subsystem->api_config ?? [];
        $headers = $config['headers'] ?? [];
        $appToken = $headers['App-Token'] ?? $config['app_token'] ?? null;
        $userToken = $config['token'] ?? null;

        if (! $appToken || ! $userToken) {
            return parent::http($subsystem);
        }

        $sessionToken = Cache::remember(
            $this->sessionCacheKey($subsystem),
            now()->addMinutes(30),
            fn () => $this->startSession($subsystem, (string) $appToken, (string) $userToken),
        );

        return Http::baseUrl(rtrim((string) $subsystem->api_url, '/'))
            ->acceptJson()
            ->timeout($config['timeout'] ?? 15)
            ->withHeaders([
                'App-Token' => $appToken,
                'Session-Token' => $sessionToken,
            ]);
    }

    private function startSession(Subsystem $subsystem, string $appToken, string $userToken): string
    {
        $response = Http::baseUrl(rtrim((string) $subsystem->api_url, '/'))
            ->acceptJson()
            ->timeout($subsystem->api_config['timeout'] ?? 15)
            ->withHeaders([
                'App-Token' => $appToken,
                'Authorization' => 'user_token '.$userToken,
            ])
            ->get('/initSession');

        $sessionToken = $response->json('session_token');

        if ($response->failed() || ! is_string($sessionToken) || $sessionToken === '') {
            throw new RuntimeException(
                'GLPI no pudo iniciar la sesión (HTTP '.$response->status().')',
            );
        }

        return $sessionToken;
    }

    private function sessionCacheKey(Subsystem $subsystem): string
    {
        return "glpi:session:{$subsystem->id}";
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        // Idempotente: si ya existe un login igual en GLPI, se reutiliza en vez de duplicar.
        $existente = $this->http($subsystem)->get('/search/User', [
            'criteria[0][field]' => 1,
            'criteria[0][searchtype]' => 'contains',
            'criteria[0][value]' => $userData['usuario'],
            'forcedisplay[0]' => 2,
            'range' => '0-0',
        ]);

        if ($existente->successful() && ! empty($existente->json('data.0.2'))) {
            $externalAccountId = (string) $existente->json('data.0.2');
            $detalle = $this->http($subsystem)->get('/User/'.$externalAccountId);

            if ($detalle->failed()) {
                return SubsystemOperationResult::fail(
                    'No se pudo verificar el estado del usuario existente en GLPI',
                    $detalle->json() ?? [],
                );
            }

            $estado = (int) $detalle->json('is_deleted') === 1
                ? 'borrado'
                : 'activo';

            return SubsystemOperationResult::ok(
                credencialUsuario: $userData['usuario'],
                externalAccountId: $externalAccountId,
                estado: $estado,
                mensaje: $estado === 'borrado'
                    ? 'Usuario ya existía en GLPI, pero está borrado'
                    : 'Usuario ya existía en GLPI, se reutilizó',
                raw: array_merge($existente->json() ?? [], ['detalle' => $detalle->json() ?? []]),
            );
        }

        $response = $this->http($subsystem)->post('/User', [
            'input' => [
                'name' => $userData['usuario'],
                'realname' => $userData['nombre_completo'],
                '_useremails' => [$userData['email_personal'] ?? null],
                'password' => $userData['password_general'] ?? null,
                'password2' => $userData['password_general'] ?? null,
                'comment' => isset($userData['cpf']) ? 'cpf: '.$userData['cpf'] : null,
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
        $response = $this->http($account->subsystem)->put('/User/'.$account->external_account_id, [
            'input' => ['is_active' => false],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->delete('/User/'.$account->external_account_id, [
            'input' => ['id' => $account->external_account_id],
            'force_purge' => false,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo eliminar el usuario en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'eliminado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->get('/User/'.$account->external_account_id);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en GLPI', $response->json() ?? []);
        }

        if ((int) $response->json('is_deleted') === 1) {
            return SubsystemOperationResult::ok(estado: 'borrado', raw: $response->json() ?? []);
        }

        $activo = (bool) $response->json('is_active');

        return SubsystemOperationResult::ok(estado: $activo ? 'activo' : 'deshabilitado', raw: $response->json() ?? []);
    }

    /**
     * Restablece la contraseña de un usuario en GLPI.
     */
    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->put('/User/'.$account->external_account_id, [
            'input' => [
                'password' => $newPassword,
                'password2' => $newPassword,
            ],
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo restablecer la contraseña en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(raw: $response->json() ?? []);
    }

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    /**
     * En GLPI el nombre real y el email son los atributos de contacto. El login
     * ('name') y el teléfono son identidad o no forman parte de lo que se
     * sincroniza, igual que en los demás drivers.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        $input = ['realname' => $userData['nombre_completo']];

        if (! blank($userData['email_personal'] ?? null)) {
            $input['_useremails'] = [$userData['email_personal']];
        }

        $response = $this->http($account->subsystem)->put('/User/'.$account->external_account_id, ['input' => $input]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudieron actualizar los datos del usuario en GLPI', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    /**
     * En GLPI el login es el campo 'name'. La búsqueda del driver es
     * 'contains', así que devuelve también "jperez" para "jperez.gomez": los
     * resultados se comparan por igualdad aquí, porque de lo contrario el
     * generador creería ocupado un login que está libre y saltaría al sufijo
     * numérico sin necesidad.
     */
    public function loginEnUso(string $login, ?string $empresa, Subsystem $subsystem): ?bool
    {
        try {
            $response = $this->http($subsystem)->get('/search/User', [
                'criteria[0][field]' => 1,
                'criteria[0][searchtype]' => 'equals',
                'criteria[0][value]' => $login,
                'forcedisplay[0]' => 2,
                'range' => '0-20',
            ]);
        } catch (\Throwable $exception) {
            $this->log('No se pudo comprobar la disponibilidad del login en GLPI', [
                'login' => $login,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            return null;
        }

        // Cada fila trae los forcedisplay: 0 = id, 1 = nombre, 2 = login.
        foreach ($data as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $nombreEnGlpi = $fila[1] ?? null;

            if (is_string($nombreEnGlpi) && strcasecmp(trim($nombreEnGlpi), $login) === 0) {
                return true;
            }
        }

        return false;
    }
}
