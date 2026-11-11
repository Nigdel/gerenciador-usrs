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
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $panel = (new DOMXPath($dom))->query("//div[@id=\"{$id}\"]")->item(0);

        $this->assertNotNull($panel, "No se encontró el panel {$id} en la ficha.");

        return (string) $panel->ownerDocument->saveHTML($panel);
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

        $this->assertStringContainsString('Identificación', $panel);
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

    public function test_el_bloque_de_identificacion_muestra_los_datos_del_gestor(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('Identificación', $html);
        $this->assertStringContainsString('Ana Silva', $html);
        $this->assertStringContainsString('ana.silva', $html);
    }

    public function test_el_bloque_de_adagio_muestra_la_credencial_y_el_estado(): void
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

        $this->assertStringContainsString('Cuenta en Adagio', $html);
        $this->assertStringContainsString('ana.silva@klios.com.br', $html);
        $this->assertStringContainsString('15040', $html);
        $this->assertStringContainsString('ANA SILVA', $html);
        $this->assertStringContainsString('FEDERAL_ST_ADM', $html);
    }

    public function test_sin_cuenta_en_adagio_se_lo_dice_en_lugar_de_dejar_huecos(): void
    {
        $html = $this->ficha($this->gestor());

        $this->assertStringContainsString('Cuenta en Adagio', $html);
        $this->assertStringContainsString('Sin cuenta en Adagio', $html);
    }
}
