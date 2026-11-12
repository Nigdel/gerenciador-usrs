<?php

namespace Tests\Feature;

use App\Enums\TermoEstado;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\Termo;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Termos de responsabilidad.
 *
 * Lo que se prueba es el ciclo de vida completo: emitir un termo de un medio
 * que GLPI atribuye al gestor, impedir que se emita para un equipo ajeno o
 * duplicado, marcarlo enviado, guardar el firmado y anularlo para poder emitir
 * otro. Los PDF se generan de verdad contra el disco falso.
 */
class TermoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El equipo que GLPI devuelve. Cada prueba puede cambiarlo antes de pedir
     * la ficha.
     *
     * @var array<string, mixed>
     */
    private array $equipo = [
        'id' => 91,
        'name' => 'Notebook Dell Latitude 5440',
        'serial' => 'DL5440-88421',
        'otherserial' => 'INV-91',
        'users_id' => '621',
        'users_id_tech' => '0',
        'is_deleted' => 0,
        'states_id' => 'Bom',
        'locations_id' => 'Sede',
        'manufacturers_id' => 'Dell',
        'computermodels_id' => 'Latitude 5440',
        'computertypes_id' => 'Notebook',
        'date_creation' => '2026-04-15 10:00:00',
    ];

    /**
     * Documentos de GLPI enlazados al equipo, indexados por id.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $documentosGlpi = [];

    /**
     * Contenido binario de cada documento, indexado por id.
     *
     * @var array<int, string>
     */
    private array $archivosGlpi = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->operador()->create());

        Cache::flush();
        Storage::fake('local');

        $this->fakeGlpi();
    }

    /**
     * GLPI responde lo justo para leer un equipo con sus componentes.
     */
    private function fakeGlpi(): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $peticion) {
            $url = $peticion->url();

            if (str_contains($url, '/initSession')) {
                return Http::response(['session_token' => 'session-token']);
            }

            if (str_contains($url, '/listSearchOptions/')) {
                return Http::response([
                    '1' => ['name' => 'Nome', 'table' => 'glpi_computers', 'field' => 'name', 'linkfield' => 'name'],
                    '2' => ['name' => 'ID', 'table' => 'glpi_computers', 'field' => 'id', 'linkfield' => 'id'],
                    '24' => ['name' => 'Técnico encarregado', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech'],
                    '70' => ['name' => 'Usuário', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id'],
                ]);
            }

            if (str_contains($url, '/search/Computer')) {
                return Http::response([
                    'totalcount' => 1,
                    'count' => 1,
                    'data' => [(string) $this->equipo['id'] => ['2' => (string) $this->equipo['id']]],
                ]);
            }

            if (str_contains($url, '/search/')) {
                return Http::response(['totalcount' => 0, 'count' => 0, 'data' => []]);
            }

            if (str_contains($url, '/Document_Item')) {
                return Http::response(array_map(
                    static fn (int $id): array => ['documents_id' => $id],
                    array_keys($this->documentosGlpi),
                ));
            }

            if (preg_match('#/Document/(\d+)\?alt=media#', $url, $coincidencias)) {
                $id = (int) $coincidencias[1];

                if (! isset($this->archivosGlpi[$id])) {
                    return Http::response([], 404);
                }

                return Http::response($this->archivosGlpi[$id], 200, ['Content-Type' => 'image/png']);
            }

            if (preg_match('#/Document/(\d+)$#', $url, $coincidencias)) {
                $id = (int) $coincidencias[1];

                return isset($this->documentosGlpi[$id])
                    ? Http::response($this->documentosGlpi[$id])
                    : Http::response([], 404);
            }

            if (str_contains($url, '/Item_DeviceProcessor')) {
                return Http::response([['deviceprocessors_id' => 'Intel Core i5-1335U']]);
            }

            if (str_contains($url, '/Item_DeviceMemory')) {
                return Http::response([['size' => 8192]]);
            }

            if (str_contains($url, '/Item_DeviceHardDrive')) {
                return Http::response([['capacity' => 256000]]);
            }

            if (str_contains($url, '/Item_DeviceNetworkCard')) {
                return Http::response([['mac' => 'AA:BB:CC:DD:EE:FF']]);
            }

            if (str_contains($url, '/NetworkPort')) {
                return Http::response([]);
            }

            if (preg_match('#/(Computer|Peripheral|Phone|Monitor|Printer)/(\d+)#', $url, $coincidencias)) {
                if ((string) $this->equipo['id'] !== $coincidencias[2]) {
                    return Http::response(['status' => 'ERROR_ITEM_NOT_FOUND'], 404);
                }

                return Http::response($this->equipo);
            }

            return Http::response([], 404);
        });
    }

    private function gestor(array $datos = []): GestorUser
    {
        return GestorUser::create(array_merge([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Klios',
            'email_personal' => 'ana.silva@gmail.com',
        ], $datos));
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

    private function cuentaGlpi(GestorUser $gestor, string $usuario = '621'): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestor->id,
            'subsystem_id' => $this->glpi()->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $usuario,
            'estado' => 'activo',
            'meta' => ['id' => (int) $usuario, 'name' => 'ana.silva'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function datosTermo(array $extra = []): array
    {
        return array_merge([
            'item_type' => 'Computer',
            'item_id' => 91,
            'tipo' => 'entrega',
            'cnpj' => '12.345.678/0001-99',
            'color' => 'Prata',
            'observaciones' => 'Equipo entregado en la sede.',
        ], $extra);
    }

    /**
     * Un PNG 1x1 válido, suficiente para comprobar que la imagen llega al PDF.
     */
    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }

    public function test_un_operador_genera_un_termo_para_un_medio_propio(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertRedirect(route('gestor-users.show', $gestor))
            ->assertSessionHasNoErrors();

        $termo = Termo::firstOrFail();

        $this->assertSame($gestor->id, $termo->gestor_user_id);
        $this->assertSame('Computer', $termo->item_type);
        $this->assertSame(91, $termo->item_id);
        $this->assertSame('Notebook Dell Latitude 5440', $termo->item_name);
        $this->assertSame(TermoEstado::Generado, $termo->estado);
        $this->assertSame('12.345.678/0001-99', $termo->valor('empresa', 'cnpj'));

        // Los componentes salen de GLPI y quedan congelados en el snapshot.
        $this->assertSame('Intel Core i5-1335U', $termo->valor('dispositivo', 'procesador'));
        $this->assertSame('8 GB', $termo->valor('dispositivo', 'ram'));
        $this->assertSame('250 GB', $termo->valor('dispositivo', 'almacenamiento'));
        $this->assertSame('aa:bb:cc:dd:ee:ff', $termo->valor('dispositivo', 'mac'));

        $this->assertNotNull($termo->pdf_generado);
        $this->assertTrue(Storage::disk('local')->exists($termo->pdf_generado));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($termo->pdf_generado));
    }

    public function test_el_termo_congela_e_imprime_las_imagenes_de_los_documentos_del_medio(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->documentosGlpi = [
            5 => [
                'id' => 5,
                'name' => 'Foto frontal',
                'filename' => 'foto-frontal.png',
                'mime' => 'image/png',
                'is_deleted' => 0,
            ],
        ];
        $this->archivosGlpi = [5 => $this->png()];

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertSessionHasNoErrors();

        $termo = Termo::firstOrFail();
        $imagenes = $termo->seccion('imagenes');

        $this->assertCount(1, $imagenes);
        $this->assertSame(5, $imagenes[0]['id']);
        $this->assertSame('foto-frontal.png', $imagenes[0]['nombre']);
        $this->assertSame('image/png', $imagenes[0]['mime']);
        $this->assertSame(base64_encode($this->png()), $imagenes[0]['base64']);

        // LibreOffice incrusta el collage como objeto del PDF, no como una
        // ruta externa que dependa de GLPI o del disco público.
        $pdf = Storage::disk('local')->get($termo->pdf_generado);
        $this->assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf);
    }

    public function test_los_documentos_que_no_son_imagenes_no_entran_en_el_termo(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->documentosGlpi = [
            6 => [
                'id' => 6,
                'name' => 'Manual del equipo',
                'filename' => 'manual.pdf',
                'mime' => 'application/pdf',
                'is_deleted' => 0,
            ],
        ];

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertSessionHasNoErrors();

        $termo = Termo::firstOrFail();

        $this->assertSame([], $termo->seccion('imagenes'));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($termo->pdf_generado));
    }

    public function test_no_se_emite_un_termo_de_un_medio_de_otro_usuario(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);
        $this->equipo['users_id'] = '999';

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertSessionHasErrors('item_id');

        $this->assertDatabaseCount('termos', 0);
    }

    public function test_un_medio_no_admite_dos_termos_vigentes(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertSessionHasNoErrors();

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertSessionHasErrors('item_id');

        $this->assertDatabaseCount('termos', 1);
    }

    public function test_un_termo_anulado_deja_emitir_otro_para_el_mismo_medio(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo());
        $primero = Termo::firstOrFail();

        $this->post(route('gestor-users.termos.anular', [$gestor, $primero]), [
            'motivo_anulacion' => 'Equipo devuelto antes de la firma.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(TermoEstado::Anulado, $primero->refresh()->estado);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('termos', 2);
        $this->assertSame(TermoEstado::Generado, Termo::latest('id')->firstOrFail()->estado);
    }

    public function test_el_termo_se_marca_enviado_y_guarda_el_pdf_firmado(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo());
        $termo = Termo::firstOrFail();

        $this->post(route('gestor-users.termos.enviado', [$gestor, $termo]))
            ->assertSessionHasNoErrors();
        $this->assertSame(TermoEstado::Enviado, $termo->refresh()->estado);

        $this->post(route('gestor-users.termos.firmado', [$gestor, $termo]), [
            'pdf_firmado' => UploadedFile::fake()->create('termo-firmado.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $termo->refresh();
        $this->assertSame(TermoEstado::Firmado, $termo->estado);
        $this->assertNotNull($termo->pdf_firmado);
        $this->assertTrue(Storage::disk('local')->exists($termo->pdf_firmado));

        $this->get(route('gestor-users.termos.pdf', [$gestor, $termo]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('gestor-users.termos.firmado.pdf', [$gestor, $termo]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_un_termo_firmado_no_admite_otro_pdf_pero_si_anulacion(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo());
        $termo = Termo::firstOrFail();

        $this->post(route('gestor-users.termos.firmado', [$gestor, $termo]), [
            'pdf_firmado' => UploadedFile::fake()->create('firmado.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->post(route('gestor-users.termos.firmado', [$gestor, $termo]), [
            'pdf_firmado' => UploadedFile::fake()->create('otro.pdf', 120, 'application/pdf'),
        ])->assertSessionHasErrors('pdf_firmado');

        // Anular sí se permite: el PDF firmado no se borra, pero el termo
        // deja de bloquear la emisión de uno nuevo.
        $this->post(route('gestor-users.termos.anular', [$gestor, $termo]), [
            'motivo_anulacion' => 'El colaborador firmó el equipo equivocado.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(TermoEstado::Anulado, $termo->refresh()->estado);
        $this->assertNotNull($termo->pdf_firmado);
        $this->assertTrue(Storage::disk('local')->exists($termo->pdf_firmado));
    }

    public function test_el_formulario_devuelve_los_datos_del_medio_y_de_la_empresa(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->getJson(route('gestor-users.termos.formulario', [$gestor, 'Computer', '91']))
            ->assertOk()
            ->assertJsonPath('usuario.nombre', 'Ana Silva')
            ->assertJsonPath('usuario.cpf', '12345678909')
            ->assertJsonPath('empresa.nombre', 'Klios')
            ->assertJsonPath('dispositivo.tipo', 'Computador')
            ->assertJsonPath('dispositivo.procesador', 'Intel Core i5-1335U')
            ->assertJsonPath('dispositivo.ram', '8 GB')
            ->assertJsonPath('dispositivo.almacenamiento', '250 GB')
            ->assertJsonPath('dispositivo.mac', 'aa:bb:cc:dd:ee:ff')
            ->assertJsonPath('estado_conservacion', 'Bom')
            ->assertJsonCount(3, 'tipos');
    }

    public function test_el_formulario_de_un_medio_ajeno_devuelve_error(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);
        $this->equipo['users_id'] = '999';

        $this->getJson(route('gestor-users.termos.formulario', [$gestor, 'Computer', '91']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_id');
    }

    public function test_un_termo_de_otro_gestor_no_se_puede_descargar_ni_anular(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo());
        $termo = Termo::firstOrFail();

        $otro = $this->gestor([
            'nombre_completo' => 'Bruno Costa',
            'cpf' => '98765432100',
            'usuario' => 'bruno.costa',
            'email_personal' => 'bruno.costa@gmail.com',
        ]);

        $this->get(route('gestor-users.termos.pdf', [$otro, $termo]))->assertNotFound();

        $this->post(route('gestor-users.termos.anular', [$otro, $termo]), [
            'motivo_anulacion' => 'No es mi termo.',
        ])->assertNotFound();

        $this->assertSame(TermoEstado::Generado, $termo->refresh()->estado);
    }

    public function test_el_auditor_puede_ver_termos_pero_no_emitirlos(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo());
        $termo = Termo::firstOrFail();

        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('gestor-users.show', $gestor))
            ->assertOk()
            ->assertSee('Termos de responsabilidade')
            ->assertSee('Notebook Dell Latitude 5440');

        $this->get(route('gestor-users.termos.pdf', [$gestor, $termo]))->assertOk();

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo())
            ->assertForbidden();
    }

    public function test_el_cartorial_muestra_el_estado_del_termo_del_medio(): void
    {
        $gestor = $this->gestor();
        $this->cuentaGlpi($gestor);

        $this->post(route('gestor-users.termos.store', $gestor), $this->datosTermo());
        $termo = Termo::firstOrFail();

        $html = (string) $this->get(route('gestor-users.show', $gestor))->assertOk()->getContent();

        $this->assertStringContainsString('Termos de responsabilidade', $html);
        $this->assertStringContainsString('PDF gerado', $html);
        $this->assertStringContainsString('Gerado', $html);
        $this->assertStringContainsString(route('gestor-users.termos.pdf', [$gestor, $termo]), $html);
    }
}
