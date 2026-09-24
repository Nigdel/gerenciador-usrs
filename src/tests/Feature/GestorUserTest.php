<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GestorUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_flash_messages_are_rendered_as_floating_notifications(): void
    {
        $response = $this->withSession(['success' => 'Usuario creado correctamente.'])
            ->get(route('gestor-users.index'));

        $response
            ->assertOk()
            ->assertSee('toast-container')
            ->assertSee('Usuario creado correctamente.')
            ->assertSee('toast-success');
    }

    public function test_gestor_user_can_be_created_with_selected_active_subsystems(): void
    {
        $adagio = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'activo' => true,
        ]);
        Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'activo' => true,
        ]);
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '99999999999',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        $this->mock(UserProvisioningService::class, function ($mock) use ($gestorUser): void {
            $mock->shouldReceive('provisionar')
                ->once()
                ->with(Mockery::on(fn (array $payload): bool => $payload['subsistemas'] === ['adagio']))
                ->andReturn([
                    'gestor_user' => $gestorUser,
                    'resultados' => [[
                        'subsistema' => 'adagio',
                        'exito' => true,
                        'mensaje' => 'Cuenta creada',
                    ]],
                ]);
        });

        $response = $this->post(route('gestor-users.store'), [
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
            'subsistemas' => ['adagio'],
        ]);

        $response
            ->assertRedirect(route('gestor-users.show', $gestorUser))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('subsystems', ['id' => $adagio->id, 'activo' => true]);
    }

    public function test_inactive_subsystems_cannot_be_selected_for_creation(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'activo' => false,
        ]);

        $response = $this->from(route('gestor-users.create'))->post(route('gestor-users.store'), [
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'empresa' => 'Empresa Teste',
            'subsistemas' => ['adagio'],
        ]);

        $response
            ->assertRedirect(route('gestor-users.create'))
            ->assertSessionHasErrors('subsistemas.0');
    }

    public function test_gestor_user_with_accounts_cannot_be_deleted(): void
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);
        $subsystem = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'activo' => true,
        ]);
        UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => 'adagio-42',
            'estado' => 'activo',
        ]);

        $this->delete(route('gestor-users.destroy', $gestorUser))
            ->assertRedirect(route('gestor-users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('gestor_users', ['id' => $gestorUser->id]);
    }
}