<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El login redirige a /dashboard, así que la vista tiene que existir.
 *
 * La vista faltaba y los tests solo comprobaban la redirección, nunca que la
 * página cargara: por eso el 500 pasó desapercibido.
 */
class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_dashboard_se_muestra_tras_iniciar_sesion(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_el_dashboard_ofrece_los_modulos_que_el_usuario_puede_ver(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('subsystems.index'))
            ->assertSee(route('users.index'));
    }

    public function test_el_dashboard_exige_sesion(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_el_dashboard_lista_los_gestores_suspendidos_pendientes_de_reactivacion(): void
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Juan Pérez',
            'cpf' => '12345678909',
            'usuario' => 'juan.perez',
            'password_general' => 'secreto123',
            'empresa' => 'Klios',
            'estado' => 'suspendido',
            'motivo_suspension_gestor' => 'Atestado',
            'suspendido_at' => '2026-10-26 13:23:26',
            'fin_suspension_gestor' => '2026-10-27',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Gestores suspensos')
            ->assertSee('Juan Pérez')
            ->assertSee('Atestado')
            ->assertSee('26/10/2026')
            ->assertSee('27/10/2026')
            ->assertSee(route('gestor-users.show', $gestorUser))
            ->assertSee(route('gestor-users.reactivate-manager', $gestorUser))
            ->assertSee('Reactivar');
    }

    public function test_el_dashboard_no_ofrece_reactivar_a_quien_no_puede_hacerlo(): void
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Juan Pérez',
            'cpf' => '12345678909',
            'usuario' => 'juan.perez',
            'password_general' => 'secreto123',
            'empresa' => 'Klios',
            'estado' => 'suspendido',
            'motivo_suspension_gestor' => 'Atestado',
        ]);

        $response = $this->actingAs(User::factory()->operador()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Juan Pérez')
            ->assertDontSee(route('gestor-users.reactivate-manager', $gestorUser));

        $this->assertStringContainsString(route('gestor-users.show', $gestorUser), (string) $response->getContent());
    }

    public function test_el_dashboard_no_lista_gestores_suspendidos_ya_reactivados(): void
    {
        GestorUser::create([
            'nombre_completo' => 'Juan Pérez',
            'cpf' => '12345678909',
            'usuario' => 'juan.perez',
            'password_general' => 'secreto123',
            'empresa' => 'Klios',
            'estado' => 'activo',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Juan Pérez');
    }
}
