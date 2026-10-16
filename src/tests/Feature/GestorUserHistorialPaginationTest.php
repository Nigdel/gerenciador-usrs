<?php

namespace Tests\Feature;

use App\Http\Controllers\GestorUserController;
use App\Models\AccountStateLog;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paginación del histórico de la ficha de usuario.
 *
 * El histórico solo crece (es un registro de auditoría: no se edita ni se
 * borra), así que antes de paginar estaba recortado a las últimas 50 entradas
 * con un `limit`: el operador no tenía ninguna forma de llegar a lo anterior.
 * Estos tests fijan las dos propiedades que hacen que eso sea navegable y no
 * solo corte: que las páginas sucesivas devuelvan entradas distintas y en el
 * orden correcto, y que una página fuera de rango no diga que no hay nada.
 *
 * El volumen de datos se deriva de GestorUserController::HISTORIAL_POR_PAGINA:
 * cambiar el tamaño de página no debe romper estos tests, porque lo que se
 * comprueba es la paginación y no un número concreto.
 */
class GestorUserHistorialPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const POR_PAGINA = GestorUserController::HISTORIAL_POR_PAGINA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->operador()->create());
    }

    private function usuario(): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678900',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);
    }

    /**
     * @param  int  $porDia  Cuántos días hacia atrás se fecha cada entrada: la
     *                       entrada 1 es la más reciente y la última la más
     *                       antigua, para que la fecha sea un orden real y no
     *                       un empate que dependa del id.
     */
    private function historial(GestorUser $usuario, int $cantidad, int $porDia = 1): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
        ]);

        for ($i = 1; $i <= $cantidad; $i++) {
            $entrada = AccountStateLog::create([
                'gestor_user_id' => $usuario->id,
                'subsystem_id' => $subsistema->id,
                'evento' => AccountStateLog::UPDATED,
                'cambios' => ['estado' => ['desde' => 'activo', 'hasta' => 'deshabilitado']],
                'actor_nombre' => 'Super Admin',
                'origen' => 'web',
                'descripcion' => "Entrada de histórico {$i}",
            ]);

            // created_at no se puede dar en bloque al crear, así que se fija
            // después.
            $entrada->forceFill(['created_at' => now()->subDays($porDia * $i)])->save();
        }
    }

    /**
     * Números de las entradas que la vista pintó, en el orden en que salió.
     *
     * @return array<int, int>
     */
    private function descripcionesDeLaPagina(GestorUser $usuario, ?int $pagina = null): array
    {
        $url = route('gestor-users.show', $usuario).($pagina === null ? '' : '?page='.$pagina);

        $contenido = $this->get($url)->assertOk()->getContent();

        preg_match_all('/Entrada de histórico (\d+)/', (string) $contenido, $coincidencias);

        return array_map('intval', $coincidencias[1]);
    }

    public function test_una_pagina_muestra_como_mucho_una_pagina(): void
    {
        $usuario = $this->usuario();
        $this->historial($usuario, self::POR_PAGINA * 3);

        $this->assertCount(self::POR_PAGINA, $this->descripcionesDeLaPagina($usuario));
    }

    public function test_las_paginas_siguientes_muestran_las_entradas_mas_antiguas(): void
    {
        $usuario = $this->usuario();
        // Tres páginas completas: ninguna queda a medias, así el desempate por
        // id se nota en el paso de una a otra.
        $this->historial($usuario, self::POR_PAGINA * 3);

        $primera = $this->descripcionesDeLaPagina($usuario);
        $segunda = $this->descripcionesDeLaPagina($usuario, pagina: 2);
        $tercera = $this->descripcionesDeLaPagina($usuario, pagina: 3);

        foreach ([$primera, $segunda, $tercera] as $pagina) {
            $this->assertCount(self::POR_PAGINA, $pagina);
        }

        // La entrada 1 es la más reciente: del más nuevo al más viejo, y las
        // páginas son contiguas (1..10, 11..20, 21..30).
        $this->assertSame(range(1, self::POR_PAGINA), $primera);
        $this->assertSame(
            range(self::POR_PAGINA + 1, self::POR_PAGINA * 2),
            $segunda,
        );
        $this->assertSame(
            range(self::POR_PAGINA * 2 + 1, self::POR_PAGINA * 3),
            $tercera,
        );
    }

    public function test_la_ultima_pagina_muestra_el_resto(): void
    {
        $usuario = $this->usuario();
        $resto = 3;
        $this->historial($usuario, self::POR_PAGINA * 2 + $resto);

        $ultima = $this->descripcionesDeLaPagina($usuario, pagina: 3);

        $this->assertCount($resto, $ultima);
    }

    public function test_una_pagina_fuera_de_rango_no_dice_que_no_hay_historico(): void
    {
        $usuario = $this->usuario();
        $this->historial($usuario, 3);

        $this->get(route('gestor-users.show', $usuario).'?page=99')
            ->assertOk()
            // Decir "Aún no hay cambios registrados" con 3 entradas en la base
            // sería mentira, y mandaría al operador a buscar un motivo que no
            // existe.
            ->assertDontSee('Aún no hay cambios registrados.')
            ->assertSee('Esta página del histórico está fuera de rango.')
            ->assertSee('Ir a la primera página');
    }

    public function test_sin_historico_sigue_diciendolo(): void
    {
        $usuario = $this->usuario();

        $this->get(route('gestor-users.show', $usuario))
            ->assertOk()
            ->assertSee('Aún no hay cambios registrados.');
    }
}
