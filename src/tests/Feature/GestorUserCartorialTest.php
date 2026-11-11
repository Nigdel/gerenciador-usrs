<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cartorial del gestor: la sección de la ficha que documenta a la persona.
 *
 * Lo que se comprueba es que el bloque de la cuenta en Adagio dice la verdad
 * sobre lo que hay guardado: si no hay cuenta, que lo diga, y si la hay, que
 * muestre la credencial y el estado reales. Un bloque que se rellena con datos
 * inventados es peor que uno vacío: el operador lo leería como verdad.
 */
class GestorUserCartorialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los equipos que GLPI devuelve. Vacío por defecto: cada test que
     * necesite equipos los pone aquí.
     *
     * @var list<array<string, mixed>>
     */
    private array $equipos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->operador()->create());

        // La caché de medios sobrevive entre pruebas (el store es de array y
        // vive en el proceso): sin limpiarla, los equipos de una prueba se
        // verían en la siguiente.
        Cache::flush();

        $this->fakeGlpi();
    }

    /**
     * GLPI responde.
     *
     * Un solo falso para toda la clase: Http::fake() acumula stubs en vez de
     * reemplazarlos, así que registrar otro después dejaría el primero
     * ganar siempre.
     */
    private function fakeGlpi(): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $peticion) {
            if (str_contains($peticion->url(), '/initSession')) {
                return Http::response(['session_token' => 'session-token']);
            }

            if (str_contains($peticion->url(), '/listSearchOptions/')) {
                // Con la numeración real de GLPI: 1 = nombre, 2 = id,
                // 70 = usuario, 24 = técnico responsable.
                return Http::response([
                    '1' => ['name' => 'Nome', 'table' => 'glpi_computers', 'field' => 'name', 'linkfield' => 'name'],
                    '2' => ['name' => 'ID', 'table' => 'glpi_computers', 'field' => 'id', 'linkfield' => 'id'],
                    '24' => ['name' => 'Técnico encarregado', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech'],
                    '70' => ['name' => 'Usuário', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id'],
                ]);
            }

            if (str_contains($peticion->url(), '/search/')) {
                // Solo hay equipos de tipo Computer: los demás tipos se
                // consultan también, pero no devuelven nada.
                if (! str_contains($peticion->url(), '/search/Computer')) {
                    return Http::response(['totalcount' => 0, 'count' => 0, 'data' => []]);
                }

                $data = [];

                foreach ($this->equipos as $equipo) {
                    // La columna 2 es el id del equipo (la 1 es el nombre).
                    $data[(string) $equipo['id']] = ['2' => (string) $equipo['id']];
                }

                return Http::response(['totalcount' => count($data), 'count' => count($data), 'data' => $data]);
            }

            if (preg_match('#/(Computer|Peripheral|Phone|Monitor|Printer)/(\d+)#', $peticion->url(), $coincidencias)) {
                // El equipo pedido: si no existe, GLPI devuelve un error.
                $pedido = null;

                foreach ($this->equipos as $equipo) {
                    if ((string) $equipo['id'] === $coincidencias[2]) {
                        $pedido = $equipo;

                        break;
                    }
                }

                if ($pedido === null) {
                    return Http::response(['status' => 'ERROR_ITEM_NOT_FOUND'], 404);
                }

                return Http::response($pedido);
            }

            return Http::response([], 404);
        });
    }

    private function gestor(): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Klios',
            'email_personal' => 'ana.silva@gmail.com',
        ]);
    }

    private function adagio(): Subsystem
    {
        return Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => [],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);
    }

    private function glpi(): Subsystem
    {
        return Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test/apirest.php',
            'api_config' => [
                'token' => 'user-token',
                'headers' => ['App-Token' => 'app-token'],
            ],
            'activo' => true,
        ]);
    }

    /**
     * Cuenta en GLPI, que es la que declara los medios atribuidos.
     */
    private function cuentaGlpi(GestorUser $gestor): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $this->glpi()->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => '621',
            'estado' => 'activo',
            'meta' => [
                'id' => 621,
                'name' => 'ana.silva',
                'realname' => 'Ana Silva',
            ],
        ]);
    }

    private function ficha(GestorUser $gestor): string
    {
        return (string) $this->get(route('gestor-users.show', $gestor))->assertOk()->getContent();
    }

    /**
     * El HTML de un panel concreto de la ficha.
     *
     * Se recorta por el DOM y no por una cadena: buscar "Histórico" en la
     * página entera no dice si está en la pestaña General o en la del
     * cartorial, que es justo lo que se quiere comprobar.
     */
    private function panel(string $html, string $id): string
    {
        $dom = $this->dom($html);

        $panel = (new DOMXPath($dom))->query("//div[@id=\"{$id}\"]")->item(0);

        $this->assertNotNull($panel, "No se encontró el panel {$id} en la ficha.");

        return (string) $dom->saveHTML($panel);
    }

    /**
     * El HTML del panel del cartorial que lleva este título.
     *
     * Los paneles del cartorial no tienen id: se localizan por su encabezado
     * (`h3`), que es como los ve el operador.
     */
    private function panelDeTitulo(string $html, string $titulo): ?string
    {
        $dom = $this->dom($html);

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//h3') as $encabezado) {
            if (trim($encabezado->textContent) !== $titulo) {
                continue;
            }

            // El h3 vive en la cabecera del panel; el panel es su abuelo.
            $panel = $xpath->query('../..', $encabezado)->item(0);

            if ($panel !== null) {
                return (string) $dom->saveHTML($panel);
            }
        }

        return null;
    }

    /**
     * El HTML como DOM.
     *
     * El charset se fija a UTF-8 antes de parsear: sin eso, DOMDocument
     * interpreta la página como Latin-1 y los acentos llegan movidos, lo que
     * hace que una comparación de texto falle sin motivo aparente.
     */
    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument;

        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return $dom;
    }

    public function test_la_ficha_tiene_la_seccion_cartorial(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('id="cartorial"', $html);
        $this->assertStringContainsString('Cartorial', $html);
    }

    public function test_el_cartorial_esta_en_una_pestaña_al_nivel_de_los_datos_generales(): void
    {
        $html = $this->ficha($this->gestor());

        // La pestaña del cartorial y la de datos generales son hermanas: las
        // dos cuelgan del mismo tablist, no una dentro de la otra.
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('id="tab-general"', $html);
        $this->assertStringContainsString('id="tab-cartorial"', $html);
        $this->assertStringContainsString('aria-controls="panel-general"', $html);
        $this->assertStringContainsString('aria-controls="panel-cartorial"', $html);

        // El panel del cartorial se muestra y se oculta con Alpine, no se
        // descarga del HTML: el contenido sigue estando en la respuesta.
        $this->assertStringContainsString("x-show=\"pestana === 'cartorial'\"", $html);
        $this->assertStringContainsString("x-show=\"pestana === 'general'\"", $html);
    }

    public function test_el_panel_general_conserva_los_datos_del_gestor(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('id="panel-general"', $html);
        $this->assertStringContainsString('Ana Silva', $html);
    }

    public function test_la_pestana_general_contiene_toda_la_ficha(): void
    {
        // "General" es la ficha de antes: datos, cuentas en subsistemas,
        // operaciones e histórico. El cartorial es lo único que se muda a su
        // propia pestaña.
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('Cuentas en subsistemas', $html);
        $this->assertStringContainsString('Operaciones', $html);
        $this->assertStringContainsString('Histórico', $html);
    }

    public function test_la_pestana_cartorial_no_contiene_la_ficha(): void
    {
        // Al revés del anterior: el cartorial no arrastra las secciones de la
        // ficha. Si aparecieran aquí, la pestaña General y la del cartorial
        // estarían mostrando lo mismo.
        $html = $this->ficha($this->gestor());

        $panel = $this->panel($html, 'panel-cartorial');

        $this->assertStringContainsString('Identificação', $panel);
        $this->assertStringNotContainsString('Cuentas en subsistemas', $panel);
        $this->assertStringNotContainsString('Histórico', $panel);
    }

    public function test_los_modales_no_quedan_dentro_de_los_paneles(): void
    {
        // Los modales son overlays (position: fixed). Dentro de un panel
        // oculto por Alpine no se verían: se quedarían ocultos al cambiar de
        // pestaña, y el operador no vería el formulario que acaba de abrir.
        $html = $this->ficha($this->gestor());

        $general = $this->panel($html, 'panel-general');

        $this->assertStringNotContainsString('x-show="showManagerSuspension"', $general);
        $this->assertStringNotContainsString('x-show="showSuspension"', $general);
        $this->assertStringNotContainsString('x-show="showCreateAccount"', $general);
    }

    public function test_la_pestana_cartorial_incluye_el_listado_de_documentos(): void
    {
        $panel = $this->panel($this->ficha($this->gestor()), 'panel-cartorial');

        $this->assertStringContainsString('Documentos', $panel);

        // Las columnas van agrupadas como en el sistema de origen: partirlas
        // daría una lectura distinta de la que tiene el operador delante.
        foreach ([
            'Documento',
            'Situação',
            'Vencimento',
            'Dias',
            'Usuário Alteração Status',
            'Data Alteração',
            'Status',
            'Cadastro',
        ] as $columna) {
            $this->assertStringContainsString($columna, $panel, "Falta la columna {$columna}.");
        }

        // "Status e Cadastro" era una sola columna y ahora son dos: si la
        // vieja siguiera ahí, la tabla seguiría mostrando el estado y la
        // fecha de registro juntas.
        $this->assertStringNotContainsString('Status e Cadastro', $panel);
    }

    public function test_el_listado_de_documentos_tiene_filas(): void
    {
        $panel = $this->panel($this->ficha($this->gestor()), 'panel-cartorial');

        // Cada fila tiene un valor por columna: una celda vacía en un listado
        // documental se lee como un documento sin documento.
        $this->assertGreaterThan(0, substr_count($panel, '<tr'));
        $this->assertStringContainsString('CTPS', $panel);
    }

    public function test_la_pestana_cartorial_incluye_el_listado_de_medios(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->equipos = [[
            'id' => 71,
            'name' => 'Notebook Dell Latitude 5440',
            'serial' => 'DL5440-88421',
            'states_id' => 'Em produção',
            'locations_id' => 'Sede — Sala 3',
            'date_creation' => '2026-04-15 10:00:00',
        ]];

        $panel = $this->panel($this->ficha($gestor), 'panel-cartorial');

        $this->assertStringContainsString('Meios', $panel);

        foreach ([
            'Tipo',
            'Descrição',
            'Nº de Série',
            'Status',
            'Localização',
            'Atribuído em',
        ] as $columna) {
            $this->assertStringContainsString($columna, $panel, "Falta la columna {$columna}.");
        }

        // Y con el equipo real que devolvió GLPI.
        $this->assertStringContainsString('Notebook Dell Latitude 5440', $panel);
        $this->assertStringContainsString('DL5440-88421', $panel);
    }

    public function test_el_nombre_del_medio_es_un_boton_que_abre_su_ficha(): void
    {
        // El nombre del medio abre la ficha con los datos que GLPI tiene de ese
        // equipo: es un botón, no un texto, porque al pulsarlo se pide el
        // detalle al servidor.
        //
        // Se busca en el HTML crudo y no en el recortado con DOMDocument: el
        // parser de HTML4 que usa DOMDocument descarta los atributos que
        // empiezan por @, que es como Alpine marca sus acciones.
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->equipos = [[
            'id' => 91,
            'name' => 'Notebook Dell Latitude 5440',
            'serial' => 'DL5440-88421',
            'states_id' => 'Ativo',
            'locations_id' => 'Sede — Sala 3',
            'date_creation' => '2026-04-15 10:00:00',
        ]];

        $html = $this->ficha($gestor);

        // El tipo y el id del medio viajan al botón para que la consulta al
        // servidor sepa qué equipo pedir.
        $this->assertStringContainsString("@click=\"abrir('Computer', '91')\"", $html);
    }

    public function test_solo_el_nombre_del_medio_es_pulsable(): void
    {
        // El resto de columnas (nº de serie, estado…) son datos, no enlaces:
        // que parecieran pulsables haría dudar de qué es clicable.
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->equipos = [[
            'id' => 91,
            'name' => 'Notebook Dell Latitude 5440',
            'serial' => 'DL5440-88421',
            'states_id' => 'Ativo',
            'locations_id' => 'Sede — Sala 3',
            'date_creation' => '2026-04-15 10:00:00',
        ]];

        $html = $this->ficha($gestor);

        $this->assertSame(
            1,
            substr_count($html, '@click="abrir('),
            'Solo el nombre del medio debe abrir su ficha.',
        );
    }

    public function test_la_ficha_de_un_medio_devuelve_sus_datos_de_glpi(): void
    {
        // Es lo que se abre al pulsar el nombre del medio en el listado.
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->equipos = [[
            'id' => 91,
            'name' => 'Notebook Dell Latitude 5440',
            'serial' => 'DL5440-88421',
            'states_id' => 'Ativo',
            'locations_id' => 'Sede — Sala 3',
            'date_creation' => '2026-04-15 10:00:00',
        ]];

        $respuesta = $this->get(route('gestor-users.medios.show', [$gestor, 'Computer', '91']))
            ->assertOk();

        $datos = $respuesta->json('datos');

        $this->assertSame('Notebook Dell Latitude 5440', $datos['Nome'] ?? null);
        $this->assertSame('DL5440-88421', $datos['Número de série'] ?? null);
        $this->assertSame('Ativo', $datos['Status'] ?? null);
        $this->assertSame('Sede — Sala 3', $datos['Localização'] ?? null);

        // Y el enlace a la ficha del equipo en el inventario de GLPI.
        $this->assertStringContainsString('/front/computer.php?id=91', (string) $respuesta->json('url_inventario'));
    }

    public function test_un_medio_que_no_existe_devuelve_404(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->get(route('gestor-users.medios.show', [$gestor, 'Computer', '999']))
            ->assertNotFound();
    }

    public function test_no_se_puede_ver_el_medio_de_otro_gestor(): void
    {
        // El medio se pide siempre dentro de una persona: comprobarlo aquí es
        // lo que impide leer el inventario de un equipo de otro gestor.
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->equipos = [[
            'id' => 91,
            'name' => 'Notebook Dell Latitude 5440',
            'serial' => 'DL5440-88421',
        ]];

        $this->get(route('gestor-users.medios.show', [$gestor, 'Computer', '91']))
            ->assertOk();

        $otro = GestorUser::create([
            'nombre_completo' => 'Bruno Costa',
            'cpf' => '98765432100',
            'password_general' => 'Password123!',
            'usuario' => 'bruno.costa',
            'empresa' => 'Klios',
        ]);

        // Sin cuenta en GLPI no hay medio que mostrar.
        $this->get(route('gestor-users.medios.show', [$otro, 'Computer', '91']))
            ->assertNotFound();
    }

    public function test_sin_medios_atribuidos_el_listado_lo_dice(): void
    {
        // Los medios vienen de GLPI. Sin ellos, el panel dice que no hay
        // medios en vez de mostrar una tabla vacía: una tabla sin filas parece
        // un fallo de la página.
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $panel = $this->panel($this->ficha($gestor), 'panel-cartorial');

        $this->assertStringContainsString('Nenhum meio atribuído', $panel);
    }

    public function test_los_medios_van_dentro_del_panel_de_glpi(): void
    {
        // Los medios son los "itens" vinculados al usuario en GLPI: se leen
        // junto a la cuenta que los declara, no al final del cartorial.
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $panel = $this->panel($this->ficha($gestor), 'panel-cartorial');

        $this->assertStringContainsString('Conta em GLPI', $panel);

        // El listado de medios tiene que estar DENTRO del panel de GLPI: si
        // cuelga de otra sección, la relación se pierde al reorderar.
        $glpi = $this->panelDeTitulo($panel, 'Conta em GLPI');

        $this->assertNotNull($glpi, 'No se encontró el panel Conta em GLPI.');
        $this->assertStringContainsString('Meios', $glpi);
    }

    public function test_sin_cuenta_en_glpi_no_hay_medios_que_atribuir(): void
    {
        // Sin cuenta en GLPI no hay "itens" atribuidos: el listado no puede
        // aparecer, porque un medio sin cuenta que lo declare no significa nada.
        $panel = $this->panel($this->ficha($this->gestor()), 'panel-cartorial');

        $this->assertStringContainsString('Sem conta em GLPI', $panel);
    }

    public function test_identificacion_y_adagio_comparten_fila_y_glpi_ocupa_la_entera(): void
    {
        // Identificación y Adagio se leen en la misma fila; GLPI, que lleva
        // dentro el listado de medios, ocupa la fila entera.
        $panel = $this->panel($this->ficha($this->gestor()), 'panel-cartorial');

        $identificacao = $this->panelDeTitulo($panel, 'Identificação');
        $adagio = $this->panelDeTitulo($panel, 'Conta em Adagio');
        $glpi = $this->panelDeTitulo($panel, 'Conta em GLPI');

        $this->assertNotNull($identificacao);
        $this->assertNotNull($adagio);
        $this->assertNotNull($glpi);

        $this->assertStringContainsString('lg:col-span-6', $identificacao);
        $this->assertStringContainsString('lg:col-span-6', $adagio);
        $this->assertStringContainsString('lg:col-span-12', $glpi);
    }

    public function test_el_bloque_de_identificacion_muestra_los_datos_del_gestor(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('Identificação', $html);
        $this->assertStringContainsString('Ana Silva', $html);
        $this->assertStringContainsString('ana.silva', $html);
    }

    public function test_el_bloque_de_adagio_muestra_el_id_externo_y_el_estado(): void
    {
        $gestor = $this->gestor();

        UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $this->adagio()->id,
            'credencial_usuario' => 'ana.silva@klios.com.br',
            'external_account_id' => '15040',
            'estado' => 'activo',
            'meta' => [
                'id' => 15040,
                'nome' => 'ANA SILVA',
                'documento' => '12345678909',
                'email' => 'ana.silva@klios.com.br',
                'tipo_contrato' => 'Spot',
                'contextos' => [
                    ['contexto' => 'FEDERAL_ST_ADM'],
                    ['contexto' => 'FEDERAL_ST_ADM'],
                ],
            ],
        ]);

        $html = $this->ficha($gestor);

        $this->assertStringContainsString('Conta em Adagio', $html);
        $this->assertStringContainsString('15040', $html);
        $this->assertStringContainsString('ID externo', $html);
        $this->assertStringContainsString('Status', $html);

        // El panel de Adagio se queda en lo esencial: el nombre, el documento
        // y el contexto ya están en la Identificación o no aportan nada.
        $this->assertStringNotContainsString('FEDERAL_ST_ADM', $html);
        $this->assertStringNotContainsString('Tipo de contrato', $html);
    }

    public function test_sin_cuenta_en_adagio_se_lo_dice_en_lugar_de_dejar_huecos(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('Conta em Adagio', $html);
        $this->assertStringContainsString('Sem conta em Adagio', $html);
    }

    public function test_la_cuenta_de_glpi_se_muestra_al_lado_de_la_de_adagio(): void
    {
        $gestor = $this->gestor();

        UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $this->adagio()->id,
            'credencial_usuario' => 'ana.silva@klios.com.br',
            'external_account_id' => '15040',
            'estado' => 'activo',
            'meta' => ['nome' => 'ANA SILVA'],
        ]);

        UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $this->glpi()->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => '621',
            'estado' => 'activo',
            'meta' => [
                'id' => 621,
                'name' => 'ana.silva',
                'realname' => 'Ana Silva',
                'authtype' => 1,
                'entities_id' => 0,
                'last_login' => '2026-11-05 11:48:30',
                'date_creation' => '2026-09-01 09:00:00',
            ],
        ]);

        $html = $this->ficha($gestor);

        $this->assertStringContainsString('Conta em GLPI', $html);

        // El panel de GLPI se queda en el login y el id externo.
        $this->assertStringContainsString('Login', $html);
        $this->assertStringContainsString('621', $html);
        $this->assertStringNotContainsString('Autenticação', $html);
        $this->assertStringNotContainsString('Último acesso', $html);
    }

    public function test_sin_cuenta_en_glpi_se_lo_dice_en_lugar_de_dejar_huecos(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('Conta em GLPI', $html);
        $this->assertStringContainsString('Sem conta em GLPI', $html);
    }
}
