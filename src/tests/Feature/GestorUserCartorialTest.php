<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->operador()->create());
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
            'api_config' => [],
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
    }

    public function test_el_listado_de_medios_tiene_filas(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $panel = $this->panel($this->ficha($gestor), 'panel-cartorial');

        $this->assertStringContainsString('Computador', $panel);
        $this->assertStringContainsString('Notebook Dell Latitude 5440', $panel);
        $this->assertStringContainsString('DL5440-88421', $panel);
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
        $this->assertStringContainsString('Notebook Dell Latitude 5440', $glpi);
    }

    public function test_sin_cuenta_en_glpi_no_hay_medios_que_atribuir(): void
    {
        // Sin cuenta en GLPI no hay "itens" atribuidos: el listado no puede
        // aparecer, porque un medio sin cuenta que lo declare no significa nada.
        $panel = $this->panel($this->ficha($this->gestor()), 'panel-cartorial');

        $this->assertStringContainsString('Sem conta em GLPI', $panel);
        $this->assertStringNotContainsString('Notebook Dell Latitude 5440', $panel);
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
