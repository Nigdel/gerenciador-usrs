<?php

namespace Tests\Feature\Subsystems\Cartorial;

use App\Models\Subsystem;
use App\Models\User;
use App\Services\Subsystems\CartorialService;
use App\Services\SubsystemServiceRegistry;
use Database\Seeders\SubsystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\PreventRequestForgery;
use Tests\TestCase;

/**
 * Cartorial: alta del subsistema en el catálogo de la web.
 *
 * Lo que se comprueba es el cableado, que es lo que puede romperse en
 * silencio: que exista un driver registrado para el slug (sin él, el listado
 * muestra el subsistema pero ninguna acción puede ejecutarse) y que el seeder
 * deje la fila creada para que aparezca en el listado.
 */
class CartorialRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_existe_un_driver_registrado_para_el_slug_cartorial(): void
    {
        $servicio = app(SubsystemServiceRegistry::class)->resolve('cartorial');

        $this->assertInstanceOf(CartorialService::class, $servicio);
    }

    public function test_el_seeder_deja_creado_el_subsistema_cartorial(): void
    {
        $this->seed(SubsystemSeeder::class);

        $subsistema = Subsystem::where('slug', 'cartorial')->first();

        $this->assertNotNull($subsistema, 'El seeder debe crear el subsistema Cartorial.');
        $this->assertSame('Cartorial', $subsistema->nombre);
        $this->assertFalse($subsistema->es_proveedor_identidad, 'El proveedor de identidad sigue siendo Adagio.');

        // Inactivo a propósito: mientras el driver no sepa crear cuentas, un
        // subsistema activo entraría en el alta de todos los usuarios nuevos y
        // cada alta fallaría con "no implementada".
        $this->assertFalse($subsistema->activo);
    }

    public function test_el_listado_de_subsistemas_muestra_cartorial(): void
    {
        Subsystem::create([
            'nombre' => 'Cartorial',
            'slug' => 'cartorial',
            'api_url' => 'https://adagio.test/api',
            'api_config' => [],
            'activo' => true,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('subsystems.index'))
            ->assertOk()
            ->assertSee('Cartorial');
    }
}
