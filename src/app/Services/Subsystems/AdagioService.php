<?php

namespace App\Services\Subsystems;

use App\Contracts\IdentityProviderInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Adagio funciona como proveedor de identidad: antes de crear un usuario
 * nuevo en el Gestor, se consulta aquí por CPF para reutilizar los datos
 * ya cadastrados (nombre, email, cpf) y para validar que un login propuesto
 * no esté ya en uso.
 *
 * NOTA: ajusta las rutas/campos exactos según la API real de Adagio;
 * aquí se deja el patrón de integración ya resuelto.
 */
class AdagioService extends BaseSubsystemService implements IdentityProviderInterface
{
    public function findByCpf(string $cpf): ?array
    {
        $subsystem = $this->resolveSubsystem();

        $response = $this->http($subsystem)->get('/usuarios/buscar', ['cpf' => $cpf]);

        if ($response->failed() || empty($response->json('data'))) {
            return null;
        }

        $data = $response->json('data');

        return [
            'nombre_completo' => $data['nombre'] ?? null,
            'email_personal' => $data['email'] ?? null,
            'cpf' => $data['cpf'] ?? $cpf,
            'usuario' => $data['login'] ?? null,
            'external_account_id' => $data['id'] ?? null,
        ];
    }

    public function existsByEmail(string $email): bool
    {
        $subsystem = $this->resolveSubsystem();

        $response = $this->http($subsystem)->get('/usuarios/buscar', ['email' => $email]);

        return $response->successful() && ! empty($response->json('data'));
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        $response = $this->http($subsystem)->post('/usuarios', [
            'nombre' => $userData['nombre_completo'],
            'cpf' => $userData['cpf'],
            'email' => $userData['email_personal'] ?? null,
            'login' => $userData['usuario'],
            'empresa' => $userData['empresa'] ?? null,
        ]);

        if ($response->failed()) {
            return SubsystemOperationResult::fail('Adagio rechazó la creación del usuario', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            credencialUsuario: $userData['usuario'],
            externalAccountId: (string) $response->json('data.id'),
            estado: 'activo',
            raw: $response->json() ?? [],
        );
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch(
            "/usuarios/{$account->external_account_id}/suspender",
            [
                'motivo' => $suspensionData['motivo_suspension'] ?? null,
                'inicio' => $suspensionData['inicio_suspension'] ?? now()->toIso8601String(),
                'fin' => $suspensionData['fin_suspension'] ?? null,
            ],
        );

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo suspender el usuario en Adagio', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'suspendido', raw: $response->json() ?? []);
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->patch("/usuarios/{$account->external_account_id}/reactivar");

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo reactivar el usuario en Adagio', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'activo', raw: $response->json() ?? []);
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->delete("/usuarios/{$account->external_account_id}");

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo deshabilitar el usuario en Adagio', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(estado: 'deshabilitado', raw: $response->json() ?? []);
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        $response = $this->http($account->subsystem)->get("/usuarios/{$account->external_account_id}");

        if ($response->failed()) {
            return SubsystemOperationResult::fail('No se pudo consultar el estado en Adagio', $response->json() ?? []);
        }

        return SubsystemOperationResult::ok(
            estado: $response->json('data.estado'),
            raw: $response->json() ?? [],
        );
    }

    private function resolveSubsystem(): Subsystem
    {
        return Subsystem::where('slug', 'adagio')->firstOrFail();
    }
}
