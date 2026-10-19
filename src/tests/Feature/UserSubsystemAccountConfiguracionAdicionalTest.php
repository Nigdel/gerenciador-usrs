<?php

namespace Tests\Feature;

use App\Contracts\SubsystemServiceInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\SubsystemServiceRegistry;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class UserSubsystemAccountConfiguracionAdicionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_y_recupera_la_configuracion_adicional_como_array(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $configuracion = [
            'chatwoot' => [
                'teams' => [['id' => 1, 'nombre' => 'Soporte']],
                'inboxes' => [['id' => 2, 'nombre' => 'Ventas']],
            ],
            'glpi' => ['perfil' => 'Super-Admin'],
            'samba_ad' => ['administrador' => true],
        ];

        $cuenta = UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'estado' => 'activo',
            'configuracion_adicional' => $configuracion,
        ]);

        $this->assertSame($configuracion, $cuenta->fresh()->configuracion_adicional);
    }

    public function test_la_configuracion_adicional_es_nula_cuando_no_se_indica(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $cuenta = UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'estado' => 'activo',
        ]);

        $this->assertNull($cuenta->fresh()->configuracion_adicional);
    }

    public function test_el_alta_desde_un_subsistema_guarda_la_configuracion_adicional(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $servicio = Mockery::mock(SubsystemServiceInterface::class);
        $servicio->shouldReceive('createUser')
            ->once()
            ->andReturn(SubsystemOperationResult::ok(
                credencialUsuario: 'ana.silva',
                externalAccountId: '42',
                estado: 'activo',
                raw: ['id' => 42],
                configuracionAdicional: [
                    'account_id' => 77,
                    'team_ids' => [4, 8],
                    'inbox_ids' => [16],
                ],
            ));

        $this->mock(SubsystemServiceRegistry::class, function ($registry) use ($servicio): void {
            $registry->shouldReceive('resolve')->with('chatwoot')->andReturn($servicio);
        });

        $resultado = app(UserProvisioningService::class)->crearEnSubsistema(
            $gestorUser,
            $subsistema,
            ['usuario' => 'ana.silva'],
        );

        $this->assertTrue($resultado['exito']);
        $this->assertSame([
            'account_id' => 77,
            'team_ids' => [4, 8],
            'inbox_ids' => [16],
        ], $resultado['cuenta']->configuracion_adicional);
    }

    public function test_la_configuracion_de_otro_subsistema_no_se_guarda_en_la_cuenta(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $servicio = Mockery::mock(SubsystemServiceInterface::class);
        $servicio->shouldReceive('createUser')
            ->once()
            // La configuración de Chatwoot no puede llegar al driver de GLPI
            // ni acabar guardada en su cuenta.
            ->with(
                Mockery::on(fn (array $datos): bool => ! isset($datos['subsystem_config'])),
                Mockery::type(Subsystem::class),
            )
            ->andReturn(SubsystemOperationResult::ok(
                credencialUsuario: 'ana.silva',
                externalAccountId: '42',
                estado: 'activo',
            ));

        $this->mock(SubsystemServiceRegistry::class, function ($registry) use ($servicio): void {
            $registry->shouldReceive('resolve')->with('glpi')->andReturn($servicio);
        });

        $resultado = app(UserProvisioningService::class)->crearEnSubsistema(
            $gestorUser,
            $subsistema,
            ['usuario' => 'ana.silva', 'subsystem_config' => ['chatwoot' => ['teams' => [5]]]],
            ['chatwoot' => ['teams' => [5]]],
        );

        $this->assertTrue($resultado['exito']);
        $this->assertNull($resultado['cuenta']->configuracion_adicional);
    }

    public function test_el_alta_de_chatwoot_recibe_los_equipos_del_payload_completo(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/teams/5/team_members' => Http::response(['payload' => ['success' => true]]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['payload' => ['success' => true]]),
        ]);

        $resultado = app(UserProvisioningService::class)->crearEnSubsistema(
            $gestorUser,
            $subsistema,
            ['nombre_completo' => 'Ana Silva', 'usuario' => 'ana.silva', 'empresa' => 'klios'],
            ['chatwoot' => ['teams' => [5]]],
        );

        $this->assertTrue($resultado['exito']);
        $this->assertSame([5], $resultado['cuenta']->configuracion_adicional['team_ids']);
        $this->assertTrue($resultado['cuenta']->configuracion_adicional['teams_asignados']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/5/team_members')
            && $request['user_ids'] === [42]);
    }

    public function test_el_alta_conserva_la_configuracion_extra_elegida_para_el_subsistema(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $servicio = Mockery::mock(SubsystemServiceInterface::class);
        $servicio->shouldReceive('createUser')
            ->once()
            ->andReturn(SubsystemOperationResult::ok(
                credencialUsuario: 'ana.silva',
                externalAccountId: '42',
                estado: 'activo',
            ));

        $this->mock(SubsystemServiceRegistry::class, function ($registry) use ($servicio): void {
            $registry->shouldReceive('resolve')->with('glpi')->andReturn($servicio);
        });

        $resultado = app(UserProvisioningService::class)->crearEnSubsistema(
            $gestorUser,
            $subsistema,
            ['usuario' => 'ana.silva'],
            ['glpi' => ['profile' => 'Super-Admin']],
        );

        $this->assertSame(
            ['profile' => 'Super-Admin'],
            $resultado['cuenta']->configuracion_adicional,
        );
    }

    public function test_la_ficha_del_gestor_muestra_la_configuracion_adicional_de_la_cuenta(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => '42',
            'estado' => 'activo',
            'configuracion_adicional' => [
                'team_ids' => [4, 8],
                'perfil' => 'Super-Admin',
                'administrador' => true,
            ],
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('gestor-users.show', $gestorUser))
            ->assertOk()
            ->assertSee('Configuración adicional')
            ->assertSee('team_ids')
            ->assertSee('4, 8')
            ->assertSee('Super-Admin')
            ->assertSee('Sí');
    }
}
