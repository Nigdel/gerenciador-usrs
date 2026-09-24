<?php

namespace App\Services\Subsystems;

use App\Contracts\SubsystemConnectionInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GlpiService extends BaseSubsystemService implements SubsystemConnectionInterface
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
            return SubsystemOperationResult::ok(
                credencialUsuario: $userData['usuario'],
                externalAccountId: (string) $existente->json('data.0.2'),
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
