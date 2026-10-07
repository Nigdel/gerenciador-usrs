<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica la matriz de roles: admin (todo), operador (alta/suspensión/reset,
 * sin borrados) y auditor (solo lectura).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeGestorUser(): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);
    }

    private function makeSubsystem(): Subsystem
    {
        return Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
            'activo' => true,
        ]);
    }

    private function makeAccount(GestorUser $gestorUser, Subsystem $subsystem): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.glpi',
            'external_account_id' => 'glpi-42',
            'estado' => 'activo',
        ]);
    }

    public function test_auditor_cannot_create_in_any_module(): void
    {
        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('gestor-users.create'))->assertForbidden();
        $this->get(route('subsystems.create'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
    }

    public function test_auditor_can_read_all_modules(): void
    {
        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('gestor-users.index'))->assertOk();
        $this->get(route('subsystems.index'))->assertOk();
        $this->get(route('users.index'))->assertOk();
    }

    public function test_auditor_cannot_edit_or_destroy(): void
    {
        $gestorUser = $this->makeGestorUser();
        $subsystem = $this->makeSubsystem();

        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('gestor-users.edit', $gestorUser))->assertForbidden();
        $this->get(route('subsystems.edit', $subsystem))->assertForbidden();
        $this->delete(route('gestor-users.destroy', $gestorUser))->assertForbidden();
        $this->delete(route('subsystems.destroy', $subsystem))->assertForbidden();
    }

    public function test_auditor_cannot_reset_passwords_or_test_connections(): void
    {
        $gestorUser = $this->makeGestorUser();
        $subsystem = $this->makeSubsystem();

        $this->actingAs(User::factory()->auditor()->create());

        $this->post(route('gestor-users.reset-password', $gestorUser))->assertForbidden();
        $this->post(route('subsystems.test-connection', $subsystem))->assertForbidden();
    }

    public function test_auditor_cannot_act_on_subsystem_accounts(): void
    {
        $gestorUser = $this->makeGestorUser();
        $subsystem = $this->makeSubsystem();
        $account = $this->makeAccount($gestorUser, $subsystem);

        $this->actingAs(User::factory()->auditor()->create());

        foreach (['disable', 'enable', 'delete'] as $operation) {
            $this->post(route('subsystems.accounts.action', [$subsystem, $account]), [
                'operation' => $operation,
            ])->assertForbidden();
        }

        $this->delete(route('gestor-users.accounts.destroy', [$gestorUser, $account]))
            ->assertForbidden();
    }

    public function test_operador_can_create_but_cannot_destroy(): void
    {
        $gestorUser = $this->makeGestorUser();
        $subsystem = $this->makeSubsystem();
        $account = $this->makeAccount($gestorUser, $subsystem);

        $this->actingAs(User::factory()->operador()->create());

        // Puede dar de alta y editar.
        $this->get(route('gestor-users.create'))->assertOk();
        $this->get(route('gestor-users.edit', $gestorUser))->assertOk();
        $this->get(route('gestor-users.accounts.create', $gestorUser))->assertOk();

        // No puede eliminar: el borrado es de admin.
        $this->delete(route('gestor-users.destroy', $gestorUser))->assertForbidden();
        $this->delete(route('subsystems.destroy', $subsystem))->assertForbidden();
        $this->delete(route('gestor-users.accounts.destroy', [$gestorUser, $account]))->assertForbidden();
    }

    public function test_operador_cannot_change_the_role_of_a_user(): void
    {
        $target = User::factory()->operador()->create(['name' => 'Objetivo']);

        $this->actingAs(User::factory()->operador()->create());

        $this->put(route('users.update', $target), [
            'name' => 'Objetivo',
            'role' => 'admin',
        ]);

        $this->assertNotSame('admin', $target->fresh()->role);
    }

    public function test_admin_can_grant_the_admin_role(): void
    {
        $target = User::factory()->operador()->create(['name' => 'Objetivo']);

        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('users.update', $target), [
            'name' => 'Objetivo',
            'role' => 'admin',
        ])->assertRedirect();

        $this->assertSame('admin', $target->fresh()->role);
    }

    public function test_admin_can_destroy_a_subsystem_account(): void
    {
        $gestorUser = $this->makeGestorUser();
        $subsystem = $this->makeSubsystem();
        $account = $this->makeAccount($gestorUser, $subsystem);

        Http::fake();

        $this->actingAs(User::factory()->admin()->create());

        $this->delete(route('gestor-users.accounts.destroy', [$gestorUser, $account]))
            ->assertRedirect(route('gestor-users.accounts.index', $gestorUser));

        $this->assertDatabaseMissing('user_subsystem_accounts', ['id' => $account->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('gestor-users.index'))->assertRedirect(route('login'));
        $this->get(route('subsystems.index'))->assertRedirect(route('login'));
    }
}
