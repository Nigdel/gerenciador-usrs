<?php

namespace Tests\Feature;

use App\Contracts\SubsystemServiceInterface;
use App\DTO\SubsystemOperationResult;
use App\Enums\OperationType;
use App\Jobs\ProcessOperationAccount;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\SubsystemServiceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GestorUserCreateAccountModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_only_offers_active_subsystems_without_an_account(): void
    {
        $gestorUser = $this->gestor();
        $conCuenta = $this->subsystem('GLPI', 'glpi');
        $disponible = $this->subsystem('Chatwoot', 'chatwoot');
        $inactivo = $this->subsystem('Sistema legado', 'legado', activo: false);
        $this->cuenta($gestorUser, $conCuenta);

        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('gestor-users.show', $gestorUser))
            ->assertOk()
            ->assertSee(route('gestor-users.accounts.store-many', $gestorUser), false)
            ->assertSee('name="subsystem_ids[]"', false)
            ->assertSee('id="nueva-cuenta-subsistema-'.$disponible->id.'"', false)
            ->assertSee('name="password_patron"', false)
            ->assertSee('id="nueva-cuenta-chatwoot-panel"', false)
            ->assertSee('id="nueva-cuenta-chatwoot-options"', false)
            ->assertSee('"checkbox":"#nueva-cuenta-subsistema-'.$disponible->id.'"', false)
            ->assertSee('"empresa":"Empresa Teste"', false)
            ->assertDontSee('name="credencial_usuario"', false)
            ->assertDontSee('name="external_account_id"', false)
            ->assertDontSee('name="estado"', false);

        $disponibles = $response->viewData('subsistemasDisponibles');

        $this->assertTrue($disponibles->contains($disponible));
        $this->assertFalse($disponibles->contains($conCuenta));
        $this->assertFalse($disponibles->contains($inactivo));
    }

    public function test_creation_page_keeps_the_chatwoot_team_selector(): void
    {
        $this->subsystem('Chatwoot', 'chatwoot');

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('gestor-users.create'))
            ->assertOk()
            ->assertSee('id="chatwoot-team-panel-', false)
            ->assertSee('id="chatwoot-team-options-', false)
            ->assertSee('subsystem_config[chatwoot][teams][]', false);
    }

    public function test_modal_enqueues_an_alta_operation_using_the_gestor_data(): void
    {
        Queue::fake();

        $gestorUser = $this->gestor();
        $chatwoot = $this->subsystem('Chatwoot', 'chatwoot');
        $glpi = $this->subsystem('GLPI', 'glpi');

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.accounts.store-many', $gestorUser), [
            'subsystem_ids' => [$chatwoot->id, $glpi->id],
            'password_patron' => 'Patron123!',
            'subsystem_config' => [
                'chatwoot' => ['teams' => ['11', '12']],
            ],
        ])
            ->assertRedirect(route('gestor-users.show', $gestorUser))
            ->assertSessionHas('success');

        $operacion = ProvisioningOperation::query()->sole();

        $this->assertSame(OperationType::Alta, $operacion->tipo);
        $this->assertSame($gestorUser->id, $operacion->gestor_user_id);
        $this->assertSame($gestorUser->usuario, $operacion->payload['usuario']);
        $this->assertSame($gestorUser->nombre_completo, $operacion->payload['nombre_completo']);
        $this->assertSame($gestorUser->cpf, $operacion->payload['cpf']);
        $this->assertSame('Patron123!', $operacion->payload['password_general']);
        $this->assertSame(['11', '12'], $operacion->payload['subsystem_config']['chatwoot']['teams']);
        $this->assertEqualsCanonicalizing(
            [$chatwoot->id, $glpi->id],
            $operacion->cuentas()->pluck('subsystem_id')->all(),
        );

        Queue::assertPushed(ProcessOperationAccount::class, 2);
    }

    public function test_created_accounts_take_credential_from_the_gestor_and_external_id_and_status_from_the_result(): void
    {
        $gestorUser = $this->gestor();
        $chatwoot = $this->subsystem('Chatwoot', 'chatwoot');
        $glpi = $this->subsystem('GLPI', 'glpi');

        $servicio = Mockery::mock(SubsystemServiceInterface::class);
        $llamadas = [];

        $servicio->shouldReceive('createUser')
            ->twice()
            ->andReturnUsing(function (array $datos, Subsystem $subsystem) use (&$llamadas) {
                $llamadas[$subsystem->slug] = $datos;

                return SubsystemOperationResult::ok(
                    credencialUsuario: $datos['usuario'],
                    externalAccountId: 'externo-'.$subsystem->id,
                    estado: 'activo',
                    raw: ['id' => $subsystem->id],
                );
            });

        $this->mock(SubsystemServiceRegistry::class, function ($registry) use ($servicio): void {
            $registry->shouldReceive('resolve')->andReturn($servicio);
        });

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.accounts.store-many', $gestorUser), [
            'subsystem_ids' => [$chatwoot->id, $glpi->id],
            'password_patron' => 'Patron123!',
            'subsystem_config' => [
                'chatwoot' => ['teams' => ['11', '12']],
            ],
        ])->assertRedirect(route('gestor-users.show', $gestorUser));

        $this->assertSame(['11', '12'], $llamadas['chatwoot']['subsystem_config']['chatwoot']['teams']);
        $this->assertSame($gestorUser->usuario, $llamadas['glpi']['usuario']);

        foreach ([$chatwoot, $glpi] as $subsystem) {
            $this->assertDatabaseHas('user_subsystem_accounts', [
                'gestor_user_id' => $gestorUser->id,
                'subsystem_id' => $subsystem->id,
                'credencial_usuario' => $gestorUser->usuario,
                'external_account_id' => 'externo-'.$subsystem->id,
                'estado' => 'activo',
            ]);
        }

        $this->assertDatabaseHas('provisioning_operations', [
            'gestor_user_id' => $gestorUser->id,
            'tipo' => 'alta',
            'estado' => 'completada',
        ]);
    }

    public function test_modal_rejects_subsystems_that_are_already_linked_or_inactive(): void
    {
        $gestorUser = $this->gestor();
        $conCuenta = $this->subsystem('GLPI', 'glpi');
        $disponible = $this->subsystem('Chatwoot', 'chatwoot');
        $inactivo = $this->subsystem('Sistema legado', 'legado', activo: false);
        $this->cuenta($gestorUser, $conCuenta);

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.accounts.store-many', $gestorUser), [
            'subsystem_ids' => [$disponible->id, $conCuenta->id, $inactivo->id],
        ])->assertSessionHasErrors(['subsystem_ids.1', 'subsystem_ids.2']);

        $this->assertDatabaseCount('user_subsystem_accounts', 1);
        $this->assertDatabaseCount('provisioning_operations', 0);
        $this->assertDatabaseMissing('user_subsystem_accounts', [
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $disponible->id,
        ]);
    }

    private function gestor(): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);
    }

    private function subsystem(string $nombre, string $slug, bool $activo = true): Subsystem
    {
        return Subsystem::create([
            'nombre' => $nombre,
            'slug' => $slug,
            'activo' => $activo,
        ]);
    }

    private function cuenta(GestorUser $gestorUser, Subsystem $subsystem): UserSubsystemAccount
    {
        return $gestorUser->subsystemAccounts()->create([
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.glpi',
            'estado' => 'activo',
        ]);
    }
}
