<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class GestorUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

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

    public function test_cpf_lookup_returns_adagio_user_data_for_the_creation_form(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => [
                'email' => 'test@example.com',
                'password' => 'secret',
            ],
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/kliosAnalise/login')) {
                return Http::response(['token' => 'adagio-token']);
            }

            return Http::response([
                'id' => 42,
                'nome' => 'Ana Silva',
                'email' => 'ana@example.com',
                'documento' => '12345678901',
            ]);
        });

        $this->get(route('gestor-users.lookup-cpf', ['cpf' => '123.456.789-01']))
            ->assertOk()
            ->assertJson([
                'found' => true,
                'user' => [
                    'cpf' => '12345678901',
                    'nombre_completo' => 'Ana Silva',
                    'email_personal' => 'ana@example.com',
                    'usuario' => 'ana',
                ],
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'documento=12345678901'));
    }

    public function test_cpf_lookup_returns_not_found_without_blocking_manual_creation(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => [
                'email' => 'test@example.com',
                'password' => 'secret',
            ],
            'es_proveedor_identidad' => true,
            'activo' => true,
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/kliosAnalise/login')) {
                return Http::response(['token' => 'adagio-token']);
            }

            return Http::response([]);
        });

        $this->get(route('gestor-users.lookup-cpf', ['cpf' => '99999999999']))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_chatwoot_team_list_is_loaded_for_the_selected_company(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => [
                'accounts' => ['klios' => 77, 'federal' => 88],
                'token' => 'test-token',
            ],
            'activo' => true,
        ]);

        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/teams' => Http::response([
                ['id' => 1, 'name' => 'Soporte Klios'],
                ['id' => 2, 'name' => 'Ventas Klios'],
            ]),
        ]);

        $this->getJson(route('subsystems.chatwoot.teams', ['subsystem' => $subsystem, 'empresa' => 'klios']))
            ->assertOk()
            ->assertJsonPath('teams.0.name', 'Soporte Klios')
            ->assertJsonPath('teams.1.name', 'Ventas Klios');
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

    public function test_creation_form_submits_adagio_even_though_its_checkbox_is_disabled(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'activo' => true,
        ]);

        $this->get(route('gestor-users.create'))
            ->assertSee('<input type="hidden" name="subsistemas[]" value="adagio">', false)
            ->assertSee('value="adagio" checked disabled', false);
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
        // Eliminar un usuario gestionado es una baja: requiere admin.
        $this->actingAs(User::factory()->admin()->create());

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

    public function test_gestor_user_show_uses_subsystem_account_actions(): void
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'activo' => true,
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.glpi',
            'external_account_id' => 'glpi-42',
            'estado' => 'activo',
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('gestor-users.show', $gestorUser))
            ->assertOk()
            ->assertSee(route('subsystems.accounts.action', [$subsystem, $account]), false)
            ->assertSee('value="disable"', false)
            ->assertSee('value="delete"', false);
    }

    public function test_gestor_user_account_action_returns_to_gestor_user_show(): void
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678903',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva.return',
            'empresa' => 'Empresa Teste',
        ]);
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
            'activo' => true,
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.glpi',
            'external_account_id' => 'glpi-42',
            'estado' => 'activo',
        ]);
        Http::fake(fn ($request) => $request->method() === 'PUT'
            ? Http::response([])
            : Http::response(['is_active' => false]));

        $this->post(route('subsystems.accounts.action', [$subsystem, $account]), [
            'operation' => 'disable',
            'return_to' => 'gestor-user',
        ])->assertRedirect(route('gestor-users.show', $gestorUser));

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'id' => $account->id,
            'estado' => 'deshabilitado',
        ]);
    }

    public function test_gestor_user_account_can_be_created_updated_and_deleted(): void
    {
        // El borrado de una cuenta en un subsistema es una baja destructiva: requiere admin.
        $this->actingAs(User::factory()->admin()->create());

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Bruno Souza',
            'cpf' => '12345678902',
            'password_general' => 'Password123!',
            'usuario' => 'bruno.souza',
            'empresa' => 'Empresa Teste',
        ]);
        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'activo' => true,
        ]);

        $this->get(route('gestor-users.accounts.create', $gestorUser))->assertOk();

        $this->post(route('gestor-users.accounts.store', $gestorUser), [
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'bruno.glpi',
            'external_account_id' => 'glpi-100',
            'estado' => 'activo',
        ])->assertRedirect(route('gestor-users.accounts.index', $gestorUser));

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'bruno.glpi',
            'external_account_id' => 'glpi-100',
        ]);

        $account = $gestorUser->subsystemAccounts()->firstOrFail();

        $this->put(route('gestor-users.accounts.update', [$gestorUser, $account]), [
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'bruno.glpi.editado',
            'external_account_id' => 'glpi-101',
            'estado' => 'suspendido',
        ])->assertRedirect(route('gestor-users.accounts.index', $gestorUser))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'id' => $account->id,
            'credencial_usuario' => 'bruno.glpi.editado',
            'external_account_id' => 'glpi-101',
            'estado' => 'suspendido',
        ]);

        $this->delete(route('gestor-users.accounts.destroy', [$gestorUser, $account]))
            ->assertRedirect(route('gestor-users.accounts.index', $gestorUser))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('user_subsystem_accounts', ['id' => $account->id]);
    }

    public function test_reset_password_post_handles_unimplemented_or_failed_resets_properly(): void
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Carlos Gomez',
            'cpf' => '12345678999',
            'password_general' => 'Password123!',
            'usuario' => 'carlos.gomez',
            'empresa' => 'Empresa Test',
        ]);

        $subsystem = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
            'activo' => true,
        ]);

        $gestorUser->subsystemAccounts()->create([
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'carlos.glpi',
            'external_account_id' => 'glpi-99',
            'estado' => 'activo',
        ]);

        // El camino ya no es un stub: sale a llamar al subsistema, así que hay que
        // falsearlo en lugar de dejar que el test intente resolver glpi.test.
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response(['error' => 'boom'], 500)]);

        $response = $this->post(route('gestor-users.reset-password', $gestorUser));

        $response->assertRedirect(route('gestor-users.show', $gestorUser));
        $response->assertSessionHas('warning');
        // Aunque los subsistemas fallen, la contraseña se entrega igual: la
        // general local sí se cambió y el gestor puede dársela al usuario para
        // que la cambie él mismo.
        $response->assertSessionHas('contrasena_temporal');
    }
}
