<?php

namespace Tests\Feature;

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
}
