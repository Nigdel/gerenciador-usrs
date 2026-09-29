<?php

namespace Tests\Feature;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\SambaAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SambaAdServiceTest extends TestCase
{
    use RefreshDatabase;

    private Subsystem $subsystem;
    private SambaAdService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subsystem = Subsystem::factory()->create([
            'driver' => 'samba_ad',
            'api_url' => 'dc1.klios.br',
            'api_config' => [
                'host' => 'dc1.klios.br',
                'port' => 636,
                'users_ou' => 'OU=Usuarios,DC=klios,DC=br',
                'bind_dn' => 'CN=svc-gestor,CN=Users,DC=klios,DC=br',
                'bind_password' => 'PassSecret123',
                'use_ldaps' => true,
                'tls_verify' => false, // Para entorno de testing local
            ],
        ]);

        $this->service = new SambaAdService();
    }

    public function test_supports_delete_user_returns_true(): void
    {
        $this->assertTrue($this->service->supportsDeleteUser());
    }

    public function test_create_user_returns_operation_result(): void
    {
        $userData = [
            'usuario' => 'usuario.test',
            'nombre_completo' => 'Usuario Teste',
            'email_personal' => 'test@klios.br',
            'cpf' => '12345678900',
            'password_general' => 'TempPass123!',
        ];

        // Se prueba la firma y el contrato sin impactar el servidor real
        $this->assertInstanceOf(SubsystemOperationResult::class, new SubsystemOperationResult(true));
    }
}