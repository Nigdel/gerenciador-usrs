<?php

namespace Tests\Feature\Subsystems;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SubsystemTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Gestionar cuentas en subsistemas incluye acciones destructivas (borrar), que son de admin.
        $this->actingAs(User::factory()->admin()->create());
    }

    protected function makeAccount(
        string $slug,
        array $apiConfig = [],
        string $company = 'klios',
        ?string $apiUrl = null,
    ): UserSubsystemAccount {
        $subsystem = Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => $apiUrl ?? 'https://'.$slug.'.test',
            'api_config' => $apiConfig,
            'activo' => true,
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => $company,
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $slug.'-42',
            'estado' => 'activo',
        ]);
    }
}
