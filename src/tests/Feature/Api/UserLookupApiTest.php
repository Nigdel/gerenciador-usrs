<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/usuarios/consultar — el paso previo al alta.
 *
 * Lo que promete es una sola clave, `userdata`, con tres estados posibles:
 * null (no está en ningún sitio), source=self (ya es un GestorUser) y
 * source=adagio (solo está en el proveedor de identidad). La integración usa
 * esa clave para decidir si crea, reutiliza o no hace nada, así que la
 * presencia de `userdata` se comprueba también en el caso null.
 */
class UserLookupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => [
                'email' => 'a@b.com',
                'password' => 'secret',
                'dominio' => 'empresa.com.br',
            ],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);
    }

    private function conToken(ApiAbility $ability = ApiAbility::ConsultarUsuario): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', [$ability->value])
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    /**
     * Adagio autentica con JWT antes de buscar; el catch-all 404 es el que
     * responde a la búsqueda por documento cuando no existe.
     */
    private function adagioNoTieneElCpf(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token']),
            'https://adagio.test/*' => Http::response(null, 404),
        ]);
    }

    private function adagioTieneElCpf(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token']),
            'https://adagio.test/proprietarios/internos*' => Http::response([
                'usuario' => [
                    'id' => 77,
                    'nome' => 'Ana Silva',
                    'email' => 'ana.silva@empresa.com.br',
                    'documento' => '12345678909',
                ],
            ]),
        ]);
    }

    private function gestorLocal(array $extra = []): GestorUser
    {
        return GestorUser::create(array_merge([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
            'email_personal' => 'ana@personal.test',
            'telefono_personal' => '11999999999',
            'direccion_particular' => 'Calle 1',
        ], $extra));
    }

    public function test_devuelve_el_gestor_user_local_sin_consultar_adagio(): void
    {
        // Sin fake: si el controlador consultara Adagio, la petición real
        // saldría y el test fallaría. El usuario local es la fuente de verdad
        // y no hay nada que preguntar fuera.
        Http::preventStrayRequests();

        $gestor = $this->gestorLocal();

        $respuesta = $this->conToken()
            ->getJson('/api/usuarios/consultar?cpf=123.456.789-09')
            ->assertOk()
            ->assertJsonPath('userdata.source', 'self')
            ->assertJsonPath('userdata.id', $gestor->id)
            ->assertJsonPath('userdata.nombre_completo', 'Ana Silva')
            ->assertJsonPath('userdata.cpf', '12345678909')
            ->assertJsonPath('userdata.usuario', 'ana.silva')
            ->assertJsonPath('userdata.empresa', 'Empresa Teste')
            ->assertJsonPath('userdata.email_personal', 'ana@personal.test')
            ->assertJsonPath('userdata.telefono_personal', '11999999999')
            ->assertJsonPath('userdata.direccion_particular', 'Calle 1')
            ->assertJsonPath('userdata.estado', 'activo');

        // La contraseña general nunca sale por la API.
        $this->assertArrayNotHasKey('password_general', $respuesta->json('userdata'));
    }

    public function test_devuelve_los_datos_de_adagio_cuando_el_cpf_no_es_un_gestor_user(): void
    {
        $this->adagioTieneElCpf();

        $this->conToken()
            ->getJson('/api/usuarios/consultar?cpf=12345678909')
            ->assertOk()
            ->assertJsonPath('userdata.source', 'adagio')
            ->assertJsonPath('userdata.nombre_completo', 'Ana Silva')
            ->assertJsonPath('userdata.cpf', '12345678909')
            ->assertJsonPath('userdata.email_personal', 'ana.silva@empresa.com.br')
            ->assertJsonPath('userdata.usuario', 'ana.silva')
            ->assertJsonPath('userdata.external_account_id', '77');
    }

    public function test_devuelve_userdata_null_cuando_el_cpf_no_esta_en_ningun_sitio(): void
    {
        $this->adagioNoTieneElCpf();

        $respuesta = $this->conToken()
            ->getJson('/api/usuarios/consultar?cpf=12345678909')
            ->assertOk();

        $this->assertArrayHasKey('userdata', $respuesta->json());
        $this->assertNull($respuesta->json('userdata'));
    }

    public function test_un_cpf_invalido_responde_422(): void
    {
        $this->conToken()
            ->getJson('/api/usuarios/consultar?cpf=11111111111')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }

    public function test_sin_cpf_responde_422(): void
    {
        $this->conToken()
            ->getJson('/api/usuarios/consultar')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }

    public function test_un_fallo_de_adagio_responde_503_en_vez_de_null(): void
    {
        // No se puede afirmar que el CPF no exista: un null haría que la
        // integración intentara un alta que quizá duplique a alguien.
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->conToken()
            ->getJson('/api/usuarios/consultar?cpf=12345678909')
            ->assertStatus(503)
            ->assertJsonPath('message', 'No se pudo consultar el CPF en Adagio.');
    }

    public function test_la_consulta_exige_la_ability_de_consultar_usuarios(): void
    {
        $this->conToken(ApiAbility::Provisionar)
            ->getJson('/api/usuarios/consultar?cpf=12345678909')
            ->assertForbidden();
    }

    public function test_la_consulta_exige_token(): void
    {
        $this->getJson('/api/usuarios/consultar?cpf=12345678909')
            ->assertUnauthorized();
    }
}
