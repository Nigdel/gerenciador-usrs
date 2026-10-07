<?php

namespace Tests\Feature\Subsystems\Accounts;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_account_can_be_added_to_a_gestor_user(): void
    {
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Acme',
        ]);
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'activo' => true,
        ]);

        $this->post(route('gestor-users.accounts.store', $user), [
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.glpi',
            'external_account_id' => 'glpi-42',
            'estado' => 'activo',
        ])->assertRedirect(route('gestor-users.accounts.index', $user));

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.glpi',
        ]);
    }
}
