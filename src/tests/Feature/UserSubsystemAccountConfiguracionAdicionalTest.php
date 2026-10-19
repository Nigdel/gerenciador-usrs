<?php

namespace Tests\Feature;

use App\Contracts\SubsystemServiceInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\SubsystemServiceRegistry;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
