<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /api/usuarios/email-propuesto — la propuesta de email previa al alta.
 *
 * Lo que promete es la misma convención que usa el alta
 * (nombre.segundoApellido@empresa.com.br) ya comprobada contra Adagio: si el
 * segundo apellido está ocupado, se propone nombre.primerApellido. La
 * comprobación la hace UsernameGeneratorService, así que aquí se verifica que
 * el endpoint la use de verdad y que exponga el aviso cuando Adagio no responde.
 */
class EmailProposalApiTest extends TestCase
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
                'dominio' => 'klios.com.br',
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
     * Adagio autentica con JWT antes de buscar y responde 404 a cualquier
     * email: todos los candidatos están libres.
     */
    private function adagioTodoLibre(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response([], 404));
    }

    /**
     * Adagio encuentra un propietario con el email indicado; el resto de
     * consultas devuelve 404 (libre).
     */
    private function adagioConEmailOcupado(string $email): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => match (true) {
            str_contains($request->url(), '/kliosAnalise/login') => Http::response(['token' => 'adagio-token']),
            str_contains($request->url(), rawurlencode($email)) => Http::response([
                'usuario' => ['id' => 77, 'email' => $email],
            ]),
            default => Http::response([], 404),
        });
    }

    private function proponer(string $nombreCompleto, string $empresa = 'Klios'): TestResponse
    {
        return $this->conToken()->getJson(route('api.usuarios.email-propuesto', [
            'nombre_completo' => $nombreCompleto,
            'empresa' => $empresa,
        ]));
    }

    public function test_propone_nombre_y_segundo_apellido_con_el_dominio_de_la_empresa(): void
    {
        $this->adagioTodoLibre();

        $this->proponer('Juan Carlos Perez Gomez')
            ->assertOk()
            ->assertExactJson([
                'email' => 'juan.gomez@klios.com.br',
                'usuario' => 'juan.gomez',
                'dominio' => 'klios.com.br',
                'no_verificados' => [],
                'mensaje' => null,
            ]);

        // La disponibilidad se comprobó con el email completo, no con el login
        // suelto: es lo que Adagio usa como credencial.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'juan.gomez%40klios.com.br'));
    }

    public function test_usa_el_primer_apellido_cuando_el_segundo_esta_ocupado_en_adagio(): void
    {
        $this->adagioConEmailOcupado('juan.gomez@klios.com.br');

        $this->proponer('Juan Carlos Perez Gomez')
            ->assertOk()
            ->assertJsonPath('email', 'juan.perez@klios.com.br')
            ->assertJsonPath('usuario', 'juan.perez')
            ->assertJsonPath('dominio', 'klios.com.br');
    }

    public function test_propone_el_primer_apellido_si_el_segundo_ya_es_un_gestor_user(): void
    {
        // Sin fake de Adagio: el login local se descarta antes de salir a la
        // red, pero el siguiente candidato sí se consulta. Se deja Adagio libre.
        $this->adagioTodoLibre();

        GestorUser::create([
            'nombre_completo' => 'Otra Persona',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'juan.gomez',
            'empresa' => 'Klios',
        ]);

        $this->proponer('Juan Carlos Perez Gomez')
            ->assertOk()
            ->assertJsonPath('email', 'juan.perez@klios.com.br');
    }

    public function test_si_adagio_no_responde_devuelve_la_propuesta_con_aviso(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response(['error' => 'boom'], 500));

        $respuesta = $this->proponer('Juan Perez')
            ->assertOk()
            ->assertJsonPath('email', 'juan.perez@klios.com.br')
            ->assertJsonPath('no_verificados.0', 'adagio');

        $this->assertNotNull($respuesta->json('mensaje'));
    }

    public function test_normaliza_el_nombre_de_la_empresa_para_el_dominio(): void
    {
        // El dominio configurado en Adagio tiene que coincidir con el de la
        // empresa para que la comprobación sea sobre el email que se propone.
        $adagio = Subsystem::query()->where('slug', 'adagio')->firstOrFail();
        $adagio->update([
            'api_config' => [
                'email' => 'a@b.com',
                'password' => 'secret',
                'dominio' => 'miempresa.com.br',
            ],
        ]);

        $this->adagioTodoLibre();

        $this->proponer('Ana Silva', 'Mi Empresa')
            ->assertOk()
            ->assertJsonPath('dominio', 'miempresa.com.br')
            ->assertJsonPath('email', 'ana.silva@miempresa.com.br');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ana.silva%40miempresa.com.br'));
    }

    public function test_un_nombre_sin_apellido_responde_422(): void
    {
        $this->proponer('Juan')
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre_completo');
    }

    public function test_nombre_y_empresa_son_obligatorios(): void
    {
        $this->conToken()
            ->getJson('/api/usuarios/email-propuesto')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre_completo', 'empresa']);
    }

    public function test_la_propuesta_exige_la_ability_de_consultar_usuarios(): void
    {
        $this->conToken(ApiAbility::Provisionar)
            ->getJson(route('api.usuarios.email-propuesto', [
                'nombre_completo' => 'Juan Perez',
                'empresa' => 'Klios',
            ]))
            ->assertForbidden();
    }

    public function test_la_propuesta_exige_token(): void
    {
        $this->getJson(route('api.usuarios.email-propuesto', [
            'nombre_completo' => 'Juan Perez',
            'empresa' => 'Klios',
        ]))->assertUnauthorized();
    }
}
