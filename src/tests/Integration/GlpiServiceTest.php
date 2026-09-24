<?php

namespace Tests\Integration;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlpiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_reuses_and_manages_a_real_glpi_user(): void
    {
        $subsystem = $this->liveSubsystem();
        $service = app(GlpiService::class);
        $userData = $this->userData();

        $connection = $service->testConnection($subsystem);
        $this->assertTrue($connection->success, $connection->mensaje ?? 'GLPI no está disponible');

        $created = $service->createUser($userData, $subsystem);

        $this->assertTrue($created->success, $created->mensaje ?? 'GLPI no creó el usuario');
        $this->assertNotEmpty($created->externalAccountId);
        $this->assertSame($userData['usuario'], $created->credencialUsuario);

        $reused = $service->createUser($userData, $subsystem);

        $this->assertTrue($reused->success, $reused->mensaje ?? 'GLPI no reutilizó el usuario');
        $this->assertSame($created->externalAccountId, $reused->externalAccountId);
        $this->assertSame('Usuario ya existía en GLPI, se reutilizó', $reused->mensaje);

        $account = $this->account($subsystem, (string) $created->externalAccountId, $userData['usuario']);

        $active = $service->getUserStatus($account);
        $this->assertTrue($active->success, $active->mensaje ?? 'No se pudo consultar el estado inicial');
        $this->assertSame('activo', $active->estado);

        $suspended = $service->suspendUser($account, []);
        $this->assertTrue($suspended->success, $suspended->mensaje ?? 'No se pudo suspender el usuario');
        $this->assertSame('suspendido', $suspended->estado);

        $reactivated = $service->reactivateUser($account);
        $this->assertTrue($reactivated->success, $reactivated->mensaje ?? 'No se pudo reactivar el usuario');
        $this->assertSame('activo', $reactivated->estado);

        $disabled = $service->disableUser($account);
        $this->assertTrue($disabled->success, $disabled->mensaje ?? 'No se pudo deshabilitar el usuario');
        $this->assertSame('deshabilitado', $disabled->estado);
    }

    private function liveSubsystem(): Subsystem
    {
        $apiUrl = env('GLPI_API_URL', env('GLPI_BASE_URL'));
        $token = env('GLPI_API_TOKEN', env('GLPI_USER_TOKEN'));

        $this->assertNotEmpty($apiUrl, 'Configura GLPI_API_URL o GLPI_BASE_URL para ejecutar la integración real.');
        $this->assertNotSame('http://your-glpi-host:8080/apirest.php', $apiUrl, 'GLPI_BASE_URL todavía usa el placeholder.');
        $this->assertNotEmpty($token, 'Configura GLPI_API_TOKEN o GLPI_USER_TOKEN para ejecutar la integración real.');
        $this->assertNotSame('your-glpi-user-token', $token, 'El token de GLPI todavía usa el placeholder.');

        return new Subsystem([
            'nombre' => 'GLPI integración',
            'slug' => 'glpi',
            'api_url' => $apiUrl,
            'api_config' => array_filter([
                'token' => $token,
                'headers' => array_filter([
                    'App-Token' => env('GLPI_APP_TOKEN'),
                ]),
            ]),
            'activo' => true,
        ]);
    }

    private function account(Subsystem $subsystem, string $externalId, string $username): UserSubsystemAccount
    {
        $user = GestorUser::create([
            'nombre_completo' => 'GLPI Integration Test',
            'cpf' => '999'.random_int(100000000, 999999999),
            'password_general' => 'Password123!',
            'usuario' => $username,
            'empresa' => 'GLPI Integration Test',
        ]);

        $account = new UserSubsystemAccount([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => $username,
            'external_account_id' => $externalId,
            'estado' => 'activo',
        ]);

        $account->setRelation('subsystem', $subsystem);

        return $account;
    }

    private function userData(): array
    {
        $username = 'copilot-glpi-'.strtolower(str()->random(8));

        return [
            'nombre_completo' => 'GLPI Integration Test',
            'cpf' => '999'.random_int(100000000, 999999999),
            'email_personal' => $username.'@example.invalid',
            'usuario' => $username,
            'empresa' => 'GLPI Integration Test',
            'password_general' => 'Password123!',
        ];
    }
}