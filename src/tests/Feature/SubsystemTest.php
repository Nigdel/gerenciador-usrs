<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubsystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_subsystem_crud_pages_are_available(): void
    {
        $this->get('/subsystems')->assertOk();
        $this->get('/subsystems/create')->assertOk();

        $subsystem = Subsystem::create([
            'nombre' => 'Test subsystem',
            'slug' => 'test-subsystem',
        ]);

        $this->get(route('subsystems.show', $subsystem))->assertOk();
        $this->get(route('subsystems.edit', $subsystem))->assertOk();
    }

    public function test_connection_button_is_available_for_glpi(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
            'activo' => true,
        ]);

        $this->get(route('subsystems.show', $subsystem))
            ->assertOk()
            ->assertSee('Probar disponibilidad');
    }

    public function test_subsystem_show_lists_associated_accounts(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Pérez',
            'cpf' => '12345678900',
            'password_general' => 'secret',
            'usuario' => 'ana.perez',
            'empresa' => 'Acme',
        ]);
        UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.perez',
            'external_account_id' => '42',
            'estado' => 'activo',
        ]);

        $this->get(route('subsystems.show', $subsystem))
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('Deshabilitar')
            ->assertSee('Eliminar');
    }

    public function test_account_state_is_updated_only_when_subsystem_confirms_it(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Pérez',
            'cpf' => '12345678900',
            'password_general' => 'secret',
            'usuario' => 'ana.perez',
            'empresa' => 'Acme',
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.perez',
            'external_account_id' => '42',
            'estado' => 'activo',
        ]);

        Http::fake(function ($request) {
            return match ($request->method()) {
                'PUT' => Http::response([], 200),
                'GET' => Http::response(['is_active' => true]),
                default => Http::response([]),
            };
        });

        $this->post(route('subsystems.accounts.action', [$subsystem, $account]), [
            'operation' => 'disable',
        ])->assertRedirect(route('subsystems.show', $subsystem))
            ->assertSessionHas('error', 'El subsistema no confirmó el cambio de estado de la cuenta.');

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'id' => $account->id,
            'estado' => 'activo',
        ]);
    }

    public function test_subsystem_can_be_created_with_json_configuration(): void
    {
        $response = $this->post('/subsystems', [
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'descripcion' => 'Proveedor principal',
            'api_url' => 'https://adagio.example.com/api',
            'api_config' => '{"timeout":30}',
            'activo' => '1',
            'es_proveedor_identidad' => '1',
        ]);

        $response
            ->assertRedirect('/subsystems')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subsystems', [
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);

        $this->assertSame(['timeout' => 30], Subsystem::firstOrFail()->api_config);
    }

    public function test_subsystem_can_be_updated(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'activo' => true,
        ]);

        $this->put(route('subsystems.update', $subsystem), [
            'nombre' => 'GLPI Helpdesk',
            'slug' => 'glpi-helpdesk',
            'activo' => '0',
        ])->assertRedirect('/subsystems');

        $this->assertDatabaseHas('subsystems', [
            'id' => $subsystem->id,
            'nombre' => 'GLPI Helpdesk',
            'activo' => false,
        ]);
    }

    public function test_subsystem_can_be_deleted_without_accounts(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Slack',
            'slug' => 'slack',
        ]);

        $this->delete(route('subsystems.destroy', $subsystem))
            ->assertRedirect('/subsystems');

        $this->assertDatabaseMissing('subsystems', ['id' => $subsystem->id]);
    }

    public function test_identity_provider_connection_can_be_tested(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => [
                'email' => 'api@example.com',
                'password' => 'secret',
            ],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);
        Http::fake(fn () => Http::response(['token' => 'test-token']));

        $this->post(route('subsystems.test-connection', $subsystem))
            ->assertRedirect(route('subsystems.show', $subsystem))
            ->assertSessionHas('success', 'Autenticación contra Adagio exitosa');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/kliosAnalise/login'));
    }

    public function test_identity_provider_connection_reports_missing_credentials(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);

        $this->post(route('subsystems.test-connection', $subsystem))
            ->assertRedirect(route('subsystems.show', $subsystem))
            ->assertSessionHas('error', 'Falta api_config.email / api_config.password del subsistema Adagio');
    }
}
