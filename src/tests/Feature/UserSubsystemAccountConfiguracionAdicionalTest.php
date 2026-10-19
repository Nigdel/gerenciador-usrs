<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
