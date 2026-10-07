<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de usuarios gestionados: búsqueda, filtros y paginación (Fase 4.1).
 */
class GestorUserListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function usuario(array $atributos = []): GestorUser
    {
        static $contador = 0;
        $contador++;

        return GestorUser::create(array_merge([
            'nombre_completo' => "Persona Generica {$contador}",
            'cpf' => str_pad((string) $contador, 11, '0', STR_PAD_LEFT),
            'usuario' => 'user'.$contador,
            'password_general' => 'secreto123',
            'empresa' => 'Klios',
        ], $atributos));
    }

    public function test_buscador_encuentra_por_nombre(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva']);
        $this->usuario(['nombre_completo' => 'Bruno Costa']);

        $this->get(route('gestor-users.index', ['q' => 'ana']))
            ->assertOk()
            ->assertSee('Ana Silva')
            ->assertDontSee('Bruno Costa');
    }

    public function test_buscador_encuentra_por_cpf(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva', 'cpf' => '12345678909']);
        $this->usuario(['nombre_completo' => 'Bruno Costa', 'cpf' => '98765432100']);

        $this->get(route('gestor-users.index', ['q' => '12345678909']))
            ->assertOk()
            ->assertSee('Ana Silva')
            ->assertDontSee('Bruno Costa');
    }

    public function test_buscador_encuentra_por_usuario(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva', 'usuario' => 'anasilva']);
        $this->usuario(['nombre_completo' => 'Bruno Costa', 'usuario' => 'brunocosta']);

        $this->get(route('gestor-users.index', ['q' => 'anasilva']))
            ->assertOk()
            ->assertSee('Ana Silva')
            ->assertDontSee('Bruno Costa');
    }

    public function test_buscador_encuentra_por_empresa(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva', 'empresa' => 'Consultoria Alfa']);
        $this->usuario(['nombre_completo' => 'Bruno Costa', 'empresa' => 'Servicios Beta']);

        $this->get(route('gestor-users.index', ['q' => 'Alfa']))
            ->assertOk()
            ->assertSee('Ana Silva')
            ->assertDontSee('Bruno Costa');
    }

    public function test_busqueda_no_devuelve_todo_cuando_no_coincide(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva']);

        $this->get(route('gestor-users.index', ['q' => 'zzzzz']))
            ->assertOk()
            ->assertSee('Ningún usuario coincide con el filtro.');
    }

    /**
     * El agrupamiento de los OR importa: sin él, el AND del estado se aplicaría
     * solo al último orWhere y el filtro de estado dejaría de funcionar.
     */
    public function test_busqueda_y_estado_se_combinan(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Activa']);
        $dadoDeBaja = $this->usuario(['nombre_completo' => 'Ana Baja']);
        $dadoDeBaja->update(['estado' => 'baja']);

        $this->get(route('gestor-users.index', ['q' => 'ana', 'estado' => 'baja']))
            ->assertOk()
            ->assertSee('Ana Baja')
            ->assertDontSee('Ana Activa');
    }

    public function test_se_puede_filtrar_por_estado(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Activa']);
        $this->usuario(['nombre_completo' => 'Bruno Baja'])->update(['estado' => 'baja']);

        $this->get(route('gestor-users.index', ['estado' => 'activo']))
            ->assertOk()
            ->assertSee('Ana Activa')
            ->assertDontSee('Bruno Baja');
    }

    public function test_se_puede_filtrar_por_subsistema(): void
    {
        $adagio = Subsystem::create(['nombre' => 'Adagio', 'slug' => 'adagio', 'activo' => true]);
        $glpi = Subsystem::create(['nombre' => 'GLPI', 'slug' => 'glpi', 'activo' => true]);

        $conAdagio = $this->usuario(['nombre_completo' => 'Ana Adagio']);
        $conGlpi = $this->usuario(['nombre_completo' => 'Bruno GLPI']);

        UserSubsystemAccount::create([
            'gestor_user_id' => $conGlpi->id,
            'subsystem_id' => $glpi->id,
            'credencial_usuario' => 'bruno',
            'external_account_id' => 'glpi-1',
            'estado' => 'activo',
        ]);

        UserSubsystemAccount::create([
            'gestor_user_id' => $conAdagio->id,
            'subsystem_id' => $adagio->id,
            'credencial_usuario' => 'ana',
            'external_account_id' => 'adagio-1',
            'estado' => 'activo',
        ]);

        $this->get(route('gestor-users.index', ['subsistema' => 'adagio']))
            ->assertOk()
            ->assertSee('Ana Adagio')
            ->assertDontSee('Bruno GLPI');

        $this->get(route('gestor-users.index', ['subsistema' => 'glpi']))
            ->assertOk()
            ->assertDontSee('Ana Adagio');
    }

    public function test_un_usuario_con_varias_cuentas_no_se_repite_en_el_listado(): void
    {
        $adagio = Subsystem::create(['nombre' => 'Adagio', 'slug' => 'adagio', 'activo' => true]);
        $glpi = Subsystem::create(['nombre' => 'GLPI', 'slug' => 'glpi', 'activo' => true]);
        $ana = $this->usuario(['nombre_completo' => 'Ana Dos Cuentas']);

        foreach ([$adagio, $glpi] as $subsistema) {
            UserSubsystemAccount::create([
                'gestor_user_id' => $ana->id,
                'subsystem_id' => $subsistema->id,
                'credencial_usuario' => 'ana',
                'external_account_id' => $subsistema->slug.'-1',
                'estado' => 'activo',
            ]);
        }

        // whereHas, no un join: con dos cuentas la fila sale una sola vez.
        $paginador = $this->get(route('gestor-users.index', ['subsistema' => 'adagio']))
            ->assertOk()
            ->viewData('gestorUsers');

        $this->assertSame(1, $paginador->total());
        $this->assertSame('Ana Dos Cuentas', $paginador->items()[0]->nombre_completo);
    }

    public function test_el_listado_se_pagina_y_conserva_el_filtro(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->usuario(['nombre_completo' => sprintf('Paginada %02d', $i)]);
        }

        $primera = $this->get(route('gestor-users.index'));
        $primera->assertOk();

        // 26 filas no caben en una página de 25.
        $this->assertCount(25, $primera->viewData('gestorUsers')->items());
        $this->assertSame(26, $primera->viewData('gestorUsers')->total());

        $segunda = $this->get(route('gestor-users.index', ['page' => 2]));
        $segunda->assertOk();
        $this->assertCount(1, $segunda->viewData('gestorUsers')->items());

        // withQueryString(): la segunda página mantiene la búsqueda.
        $filtrada = $this->get(route('gestor-users.index', ['q' => 'Paginada', 'page' => 2]));
        $filtrada->assertOk();
        $this->assertCount(1, $filtrada->viewData('gestorUsers')->items());
    }

    public function test_un_estado_invalido_no_llega_a_la_consulta(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva']);

        // Rule::enum lo rechaza y la redirección de vuelta deja el listado vacío
        // de filtro, no un 500 por un valor de enumeración inexistente.
        $this->get(route('gestor-users.index', ['estado' => 'inventado']))
            ->assertRedirect()
            ->assertSessionHasErrors('estado');
    }

    public function test_operador_tiene_acceso_al_listado_filtrado(): void
    {
        $this->usuario(['nombre_completo' => 'Ana Silva']);

        $this->get(route('gestor-users.index', ['q' => 'ana', 'estado' => 'activo']))
            ->assertOk();
    }
}
