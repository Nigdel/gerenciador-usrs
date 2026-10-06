<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica que la API exige token y que el token se limita a sus abilities.
 *
 * Las peticiones van con cabecera Authorization y no con actingAs(): con
 * sanctum.guard vacío la sesión web no autentica la API, que es justo lo que
 * se quiere comprobar. Tampoco se usa Sanctum::actingAs(), cuyo token
 * transitorio responde true a cualquier ability.
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token'], 200),
            'adagio.test/*' => Http::response(null, 404),
        ]);
    }

    private function payloadProvisionar(): array
    {
        return [
            'cpf' => '12345678901',
            'nombre_completo' => 'Api Test',
            'empresa' => 'Test Company',
        ];
    }

    private function payloadSuspender(): array
    {
        return [
            'cpf' => '12345678901',
            'motivo_suspension' => 'Prueba',
        ];
    }

    /**
     * Emite un token real con las abilities dadas y devuelve su texto plano.
     */
    private function bearer(array $abilities, string $nombre = 'integracion'): string
    {
        return User::factory()->operador()->create()
            ->createToken($nombre, $abilities)
            ->plainTextToken;
    }

    private function como(string $plain): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    public function test_api_rechaza_peticiones_sin_token(): void
    {
        $this->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertUnauthorized();

        $this->postJson('/api/usuarios/suspender', $this->payloadSuspender())
            ->assertUnauthorized();
    }

    public function test_api_rechaza_un_token_invalido(): void
    {
        $this->como('token-que-no-existe')
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertUnauthorized();
    }

    /**
     * Una sesión web autenticada no debe dar acceso a la API: Sanctum le
     * asignaría un TransientToken, que responde true a cualquier ability.
     */
    public function test_una_sesion_web_no_autentica_la_api(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertUnauthorized();
    }

    public function test_token_sin_la_ability_recibida_no_puede_provisionar(): void
    {
        $this->como($this->bearer([ApiAbility::Suspender->value]))
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertForbidden();
    }

    public function test_token_sin_la_ability_recibida_no_puede_suspender(): void
    {
        $this->como($this->bearer([ApiAbility::Provisionar->value]))
            ->postJson('/api/usuarios/suspender', $this->payloadSuspender())
            ->assertForbidden();
    }

    public function test_token_sin_abilities_no_puede_hacer_nada(): void
    {
        $plain = $this->bearer([]);

        $this->como($plain)
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertForbidden();

        $this->como($plain)
            ->postJson('/api/usuarios/suspender', $this->payloadSuspender())
            ->assertForbidden();
    }

    /**
     * El rol del dueño del token no amplía lo que el token puede hacer:
     * la API ve las abilities, no el rol.
     */
    public function test_un_admin_no_puede_saltarse_las_abilities(): void
    {
        $admin = User::factory()->admin()->create();

        $this->como($admin->createToken('integracion', [ApiAbility::Suspender->value])->plainTextToken)
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertForbidden();
    }

    public function test_token_con_la_ability_correcta_pasa_el_control(): void
    {
        $response = $this->como($this->bearer([ApiAbility::Provisionar->value]))
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar());

        // Estos tests comprueban el control de acceso, no el alta: con la
        // ability correcta la petición pasa de largo (404 = no hay subsistemas).
        $this->assertNotContains($response->getStatusCode(), [401, 403]);
    }

    public function test_un_token_revocado_deja_de_servir(): void
    {
        $this->crearSubsistema();

        $user = User::factory()->operador()->create();
        $plain = $user->createToken('a-revocar', [ApiAbility::Provisionar->value])->plainTextToken;

        $this->como($plain)
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertCreated();

        $user->tokens()->where('name', 'a-revocar')->delete();

        // El guard memoriza el usuario resuelto; forgetGuards() simula una
        // petición nueva, que en producción llega con la app recién construida.
        $this->app['auth']->forgetGuards();

        $this->como($plain)
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertUnauthorized();
    }

    public function test_la_api_limita_las_peticiones_por_token(): void
    {
        $this->crearSubsistema();

        $plain = $this->bearer([ApiAbility::Provisionar->value], 'limitado');

        // 60 por minuto es el límite por token del limiter 'api'.
        for ($i = 0; $i < 60; $i++) {
            $this->como($plain)
                ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
                ->assertCreated();
        }

        $this->como($plain)
            ->postJson('/api/usuarios/provisionar', $this->payloadProvisionar())
            ->assertStatus(429);
    }

    /**
     * Sin subsistemas activos el alta responde 404 (firstOrFail), así que
     * para probar el límite hace falta uno contra el que aprovisionar.
     */
    private function crearSubsistema(): void
    {
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => ['email' => 'a@b.com', 'password' => 'secret'],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);
    }
}
