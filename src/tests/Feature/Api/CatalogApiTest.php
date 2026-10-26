<?php

namespace Tests\Feature\Api;

use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo de la API: health, subsistemas y empresas.
 *
 * Solo /api/health es público; el resto exige un token de Sanctum, aunque no
 * una ability concreta: son lecturas y cualquier integración autenticada puede
 * consultarlas.
 */
class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private function conToken(): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test')
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    private function subsistema(array $atributos = []): Subsystem
    {
        return Subsystem::create(array_merge([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'descripcion' => 'Proveedor de identidad',
            'api_url' => 'https://adagio.test',
            'api_config' => [],
            'activo' => true,
        ], $atributos));
    }

    public function test_health_no_requiere_token_y_devuelve_el_estado_del_sistema(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('estado', 'ok')
            ->assertJsonPath('comprobaciones.base_de_datos', 'ok')
            ->assertJsonPath('comprobaciones.cache', 'ok')
            ->assertJsonPath('comprobaciones.almacenamiento', 'ok')
            ->assertJsonStructure([
                'estado',
                'fecha',
                'comprobaciones' => ['base_de_datos', 'cache', 'almacenamiento'],
                'aplicacion' => ['nombre', 'version_php', 'version_laravel'],
            ]);
    }

    public function test_lista_los_subsistemas_con_estado_y_ultima_consulta(): void
    {
        $this->subsistema([
            'last_connection_test_at' => now(),
            'last_connection_test_success' => true,
        ]);
        $this->subsistema([
            'nombre' => 'Email',
            'slug' => 'email',
            'activo' => false,
        ]);
        $this->subsistema([
            'nombre' => 'Glpi',
            'slug' => 'glpi',
            'last_connection_test_at' => now()->subHour(),
            'last_connection_test_success' => false,
        ]);
        $this->subsistema([
            'nombre' => 'Slack',
            'slug' => 'slack',
        ]);

        $this->conToken()->getJson('/api/subsistemas')
            ->assertOk()
            ->assertJsonCount(4, 'subsistemas')
            ->assertJsonPath('subsistemas.0.nombre', 'Adagio')
            ->assertJsonPath('subsistemas.0.estado', 'operativo')
            ->assertJsonPath('subsistemas.0.ultima_consulta_correcta', true)
            ->assertJsonPath('subsistemas.1.nombre', 'Email')
            ->assertJsonPath('subsistemas.1.estado', 'inactivo')
            ->assertJsonPath('subsistemas.1.ultima_consulta_estado', null)
            ->assertJsonPath('subsistemas.2.nombre', 'Glpi')
            ->assertJsonPath('subsistemas.2.estado', 'con_error')
            ->assertJsonPath('subsistemas.3.nombre', 'Slack')
            ->assertJsonPath('subsistemas.3.estado', 'sin_verificar');
    }

    public function test_devuelve_la_configuracion_adicional_de_chatwoot(): void
    {
        $chatwoot = $this->subsistema([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_config' => [
                'token' => 'secreto-que-no-debe-salir',
                'accounts' => ['klios' => 1, 'federal' => 2],
                'inboxes' => ['klios' => 16, 'federal' => 13],
            ],
        ]);

        $this->conToken()->getJson('/api/subsistema/'.$chatwoot->id)
            ->assertOk()
            ->assertJsonPath('id', $chatwoot->id)
            ->assertJsonPath('slug', 'chatwoot')
            ->assertJsonPath('configuracion_adicional.cuentas_por_empresa', ['klios', 'federal'])
            ->assertJsonPath('configuracion_adicional.inboxes_por_empresa.klios', 16)
            ->assertJsonPath('configuracion_adicional.inboxes_por_empresa.federal', 13)
            ->assertJsonPath('configuracion_adicional.campos_soportados.teams.tipo', 'array<integer>')
            ->assertJsonPath('configuracion_adicional.campos_soportados.inbox_ids.tipo', 'array<integer>')
            ->assertDontSee('secreto-que-no-debe-salir');

        // El slug sirve igual que el id, para no obligar a resolver uno a partir del otro.
        $this->conToken()->getJson('/api/subsistema/chatwoot')
            ->assertOk()
            ->assertJsonPath('slug', 'chatwoot');
    }

    public function test_devuelve_la_entidad_de_adagio_y_sus_valores(): void
    {
        $adagio = $this->subsistema([
            'api_config' => ['entidad_default' => 'klios', 'password' => 'no-salir'],
        ]);

        $this->conToken()->getJson('/api/subsistema/'.$adagio->id)
            ->assertOk()
            ->assertJsonPath('configuracion_adicional.entidad_predeterminada', 'klios')
            ->assertJsonPath('configuracion_adicional.campos_soportados.entidad.valores', ['klios', 'federal'])
            ->assertDontSee('no-salir');
    }

    public function test_un_subsistema_inexistente_devuelve_404(): void
    {
        $this->conToken()->getJson('/api/subsistema/999')->assertNotFound();
        $this->conToken()->getJson('/api/subsistema/no-existe')->assertNotFound();
    }

    public function test_el_catalogo_exige_token_y_health_no(): void
    {
        $this->getJson('/api/subsistemas')->assertUnauthorized();
        $this->getJson('/api/subsistema/1')->assertUnauthorized();
        $this->getJson('/api/empresas')->assertUnauthorized();

        $this->getJson('/api/health')->assertOk();
    }

    public function test_empresas_devuelve_las_aceptadas(): void
    {
        $this->conToken()->getJson('/api/empresas')
            ->assertOk()
            ->assertExactJson(['empresas' => ['Klios', 'Federal']]);
    }
}
