<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiMediosService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Medios del gestor: los equipos atribuidos al usuario en GLPI.
 *
 * GLPI no expone un endpoint único para "los equipos de este usuario": el
 * vínculo son las columnas users_id (asignados) y users_id_tech (técnicos) de
 * cada tipo de equipo, así que hay que buscarlos por criterio y leer cada uno.
 *
 * Lo que se comprueba aquí es el cableado: que se busquen por el usuario
 * correcto, con el criterio que GLPI acepta de verdad, que se lean los tipos
 * de equipo que interesan y que el resultado se traduzca a las filas del
 * listado. Un equipo que se busca por otro usuario o que no aparece en el
 * listado es un fallo que el operador no vería: la tabla simplemente saldría
 * vacía.
 */
class GlpiMediosTest extends SubsystemTestCase
{
    /**
     * El número de opción de búsqueda de cada columna en GLPI.
     *
     * Son los valores reales de la instancia: GLPI numera las opciones de
     * forma distinta en cada tipo de equipo, así que el servicio las lee de
     * `listSearchOptions` en vez de fijarlas. Aquí se fijan para que el test
     * no dependa de esa lectura.
     *
     * @var array<string, mixed>
     */
    private const OPCIONES = [
        '1' => ['name' => 'Nome', 'table' => 'glpi_computers', 'field' => 'name', 'linkfield' => 'name', 'datatype' => 'itemlink'],
        '2' => ['name' => 'ID', 'table' => 'glpi_computers', 'field' => 'id', 'linkfield' => 'id', 'datatype' => 'number'],
        '24' => ['name' => 'Técnico encarregado', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech', 'datatype' => 'dropdown'],
        '70' => ['name' => 'Usuário', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'datatype' => 'dropdown'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // La sesión de GLPI se cachea entre pruebas: sin limpiarla, una
        // respuesta falsa de la prueba anterior se cuela en la siguiente.
        Cache::flush();
    }

    private function medios(): GlpiMediosService
    {
        return app(GlpiMediosService::class);
    }

    /**
     * La cuenta en GLPI, con su id externo real (el de GLPI, no el de la
     * tabla local).
     */
    private function cuenta(string $externalId = '532'): UserSubsystemAccount
    {
        $subsistema = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
            'api_config' => [
                'token' => 'user-token',
                'headers' => ['App-Token' => 'app-token'],
            ],
            'activo' => true,
        ]);

        $usuario = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Klios',
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $usuario->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $externalId,
            'estado' => 'activo',
        ]);
    }

    /**
     * Falsea las cuatro llamadas que hace el lector: sesión, opciones de
     * búsqueda, búsqueda de equipos y detalle de cada equipo.
     */
    private function fakeGlpi(?array $busqueda = null, ?array $detalle = null): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $peticion) use ($busqueda, $detalle) {
            if (str_contains($peticion->url(), '/initSession')) {
                return Http::response(['session_token' => 'session-token']);
            }

            if (str_contains($peticion->url(), '/listSearchOptions/')) {
                return Http::response(self::OPCIONES);
            }

            if (str_contains($peticion->url(), '/search/')) {
                // Solo hay equipos de tipo Computer: los demás tipos se
                // consultan también, pero no devuelven nada.
                if (! str_contains($peticion->url(), '/search/Computer')) {
                    return Http::response(['totalcount' => 0, 'count' => 0, 'data' => []]);
                }

                return Http::response($busqueda ?? ['totalcount' => 1, 'count' => 1, 'data' => ['91' => ['2' => '91']]]);
            }

            if (preg_match('#/(Computer|Peripheral|Phone|Monitor|Printer)/(\d+)#', $peticion->url(), $coincidencias)) {
                return Http::response($detalle ?? [
                    'id' => (int) $coincidencias[2],
                    'name' => 'DESKTOP-OHPVAAT',
                    'serial' => '09P49QBR108312',
                    'states_id' => 'Ativo',
                    'locations_id' => 'Klios - Setor Sul',
                    'date_creation' => '2024-09-05 13:06:09',
                ]);
            }

            return Http::response([], 404);
        });
    }

    public function test_devuelve_los_medios_del_usuario(): void
    {
        $this->fakeGlpi();

        $medios = $this->medios()->medios($this->cuenta());

        $this->assertCount(1, $medios);
        $this->assertSame('Computador', $medios[0]['Tipo']);
        $this->assertSame('DESKTOP-OHPVAAT', $medios[0]['Descrição']);
        $this->assertSame('09P49QBR108312', $medios[0]['Nº de Série']);
        $this->assertSame('Ativo', $medios[0]['Status']);
        $this->assertSame('Klios - Setor Sul', $medios[0]['Localização']);
        $this->assertSame('05/09/2024', $medios[0]['Atribuído em']);
    }

    public function test_busca_los_equipos_por_el_usuario_de_glpi(): void
    {
        $this->fakeGlpi();

        $this->medios()->medios($this->cuenta('532'));

        // El criterio de un dropdown (el usuario) se busca con `equals` y el
        // id del usuario: con `contains` GLPI trae también los homónimos
        // ("ana" trae "alana.barbosa").
        Http::assertSent(fn (Request $peticion) => str_contains($peticion->url(), '/search/Computer')
            && ($peticion['criteria[0][searchtype]'] ?? null) === 'equals'
            && ($peticion['criteria[0][value]'] ?? null) === '532');
    }

    public function test_busca_tambien_los_equipos_tecnicos(): void
    {
        $this->fakeGlpi();

        $this->medios()->medios($this->cuenta('532'));

        // Un equipo puede estar asignado (users_id) o ser gestionado por el
        // usuario (users_id_tech): son dos vínculos distintos y el listado
        // tiene que traer los dos.
        Http::assertSent(fn (Request $peticion) => str_contains($peticion->url(), '/search/Computer')
            && ($peticion['criteria[1][value]'] ?? null) === '532');
    }

    public function test_consulta_los_tipos_de_equipo_configurados(): void
    {
        $this->fakeGlpi();

        $this->medios()->medios($this->cuenta());

        foreach (['Computer', 'Peripheral', 'Phone', 'Monitor', 'Printer'] as $tipo) {
            Http::assertSent(fn (Request $peticion) => str_contains($peticion->url(), '/search/'.$tipo));
        }
    }

    public function test_un_estado_sin_asignar_no_se_muestra_como_cero(): void
    {
        // Con expand_dropdowns, GLPI devuelve 0 cuando el equipo no tiene
        // estado: es un id sin desplegable, no un estado que se llame "0".
        $this->fakeGlpi(detalle: [
            'id' => 91,
            'name' => 'PC1000-PC',
            'serial' => '0A299QCWB00279V',
            'states_id' => 0,
            'locations_id' => 0,
            'date_creation' => '2024-09-09 08:00:00',
        ]);

        $medios = $this->medios()->medios($this->cuenta());

        $this->assertNull($medios[0]['Status']);
        $this->assertNull($medios[0]['Localização']);
    }

    public function test_sin_respuesta_de_glpi_no_devuelve_medios_pero_no_rompe(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $peticion) => str_contains($peticion->url(), '/initSession')
            ? Http::response(['session_token' => 'session-token'])
            : Http::response([], 500));

        $medios = $this->medios()->medios($this->cuenta());

        // Un GLPI caído no puede tumbar la ficha: se muestra el panel vacío.
        $this->assertSame([], $medios);
    }

    public function test_sin_cuenta_en_glpi_no_hay_medios(): void
    {
        $this->fakeGlpi();

        $cuenta = $this->cuenta();
        $cuenta->update(['external_account_id' => '']);

        $this->assertSame([], $this->medios()->medios($cuenta));
    }
}
