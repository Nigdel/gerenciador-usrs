<?php

namespace Tests\Feature;

use App\Models\Subsystem;
use App\Models\User;
use App\Services\Subsystems\EmailService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Botón de "probar conexión de todos los subsistemas" del listado.
 *
 * El botón es la versión web de `subsystems:test-conection`: misma pasada, mismo
 * criterio para distinguir "caído" de "no probado". Lo que se comprueba aquí es
 * el cableado web: autorización, que el filtro de búsqueda sobreviva al POST y
 * que lo que se flasha corresponda a lo que pasó.
 */
class TestAllSubsystemsConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    private function subsistema(string $slug, bool $activo = true): Subsystem
    {
        config(['subsystems.drivers.'.$slug => EmailService::class]);

        return Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => 'https://'.$slug.'.test',
            'api_config' => [],
            'activo' => $activo,
        ]);
    }

    private function responden(array $ademas = []): void
    {
        Http::preventStrayRequests();
        Http::fake($ademas + ['*' => Http::response([], 200)]);
    }

    public function test_el_listado_ofrece_el_boton_de_prueba_global(): void
    {
        $this->actingAs(User::factory()->operador()->create());

        $this->get(route('subsystems.index'))
            ->assertOk()
            ->assertSee('Testar todos os subsistemas ativos');
    }

    public function test_auditor_no_ve_el_boton_ni_puede_disparar_la_prueba(): void
    {
        $this->subsistema('sano');
        $this->responden();
        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('subsystems.index'))
            ->assertOk()
            ->assertDontSee('Testar todos os subsistemas ativos');

        $this->post(route('subsystems.test-connections'))->assertForbidden();
    }

    public function test_prueba_los_activos_y_avisa_cuando_todo_conecta(): void
    {
        $sano = $this->subsistema('sano');
        $otro = $this->subsistema('otro-sano');
        $this->subsistema('apagado', activo: false);
        $this->responden();
        $this->actingAs(User::factory()->operador()->create());

        $this->post(route('subsystems.test-connections'))
            ->assertRedirect(route('subsystems.index'))
            ->assertSessionHas('success', 'Conectaron 2 de 2 subsistemas probados.');

        $this->assertTrue($sano->fresh()->last_connection_test_success);
        $this->assertTrue($otro->fresh()->last_connection_test_success);
    }

    public function test_avisa_cuando_alguno_no_conecta_y_sigue_probando_el_resto(): void
    {
        $roto = $this->subsistema('roto');
        $sano = $this->subsistema('sano');
        Http::preventStrayRequests();
        Http::fake([
            'https://sano.test/*' => Http::response([], 200),
            'https://roto.test/*' => Http::response([], 500),
        ]);
        $this->actingAs(User::factory()->operador()->create());

        $this->post(route('subsystems.test-connections'))
            ->assertRedirect(route('subsystems.index'))
            ->assertSessionHas('error', 'Sin conexión con roto.');

        $this->assertFalse($roto->fresh()->last_connection_test_success);
        $this->assertTrue($sano->fresh()->last_connection_test_success);
    }

    public function test_avisa_cuando_no_hay_subsistemas_activos(): void
    {
        $this->subsistema('apagado', activo: false);
        $this->responden();
        $this->actingAs(User::factory()->operador()->create());

        $this->post(route('subsystems.test-connections'))
            ->assertRedirect(route('subsystems.index'))
            ->assertSessionHas('warning', 'No hay subsistemas activos que probar.');

        Http::assertNothingSent();
    }

    public function test_conerva_el_filtro_de_busqueda_al_volver_al_listado(): void
    {
        $this->subsistema('sano');
        $this->responden();
        $this->actingAs(User::factory()->operador()->create());

        $this->post(route('subsystems.test-connections'), ['q' => 'sano'])
            ->assertRedirect(route('subsystems.index', ['q' => 'sano']));
    }
}
