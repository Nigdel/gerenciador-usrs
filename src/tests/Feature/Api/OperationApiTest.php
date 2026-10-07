<?php

namespace Tests\Feature\Api;

use App\Enums\ApiAbility;
use App\Enums\OperationType;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 1.3 — Consulta de operaciones por API.
 *
 * Es el cierre del circuito con el que arrancan las integraciones: el alta
 * responde con un `operacion_id` y encola el trabajo real, así que sin este
 * endpoint no hay forma de saber si las cuentas se crearon.
 *
 * Las peticiones van con cabecera Authorization y no con actingAs(): con
 * sanctum.guard vacío la sesión web no autentica la API. Tampoco se usa
 * Sanctum::actingAs(), cuyo token transitorio responde true a cualquier ability
 * y no probaría nada de la comprobación de abilities.
 */
class OperationApiTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $subsistema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        $this->subsistema = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
            'api_config' => [],
            'activo' => true,
        ]);
    }

    /**
     * @param  array<int, ApiAbility>  $abilities
     */
    private function conToken(array $abilities): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', array_map(fn (ApiAbility $a) => $a->value, $abilities))
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }

    /**
     * Crea una operación y devuelve su uuid. El origen se fuerza en la base
     * porque `origen` lo calcula la petición real, no el modelo.
     */
    private function operacion(string $origen = 'api'): string
    {
        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->subsistema]),
            ['password_general' => 'Password123!'],
        );

        $operacion->forceFill(['origen' => $origen])->save();

        return $operacion->uuid;
    }

    public function test_consulta_una_operacion_por_su_uuid(): void
    {
        $uuid = $this->operacion();

        $this->conToken([ApiAbility::Consultar])
            ->getJson(route('api.operaciones.show', $uuid))
            ->assertOk()
            ->assertJsonPath('uuid', $uuid)
            ->assertJsonPath('tipo', 'alta')
            // Encolada y sin ejecutar: eso es lo que la integración ve
            // inmediatamente después del alta.
            ->assertJsonPath('terminado', false)
            ->assertJsonPath('cuentas.0.subsistema', 'email')
            ->assertJsonPath('cuentas.0.estado', 'pendiente');
    }

    public function test_no_sin_token(): void
    {
        $this->getJson(route('api.operaciones.show', $this->operacion()))
            ->assertUnauthorized();
    }

    public function test_no_sin_la_ability_de_consultar(): void
    {
        // Un token que puede dar de alta no puede por el mismo hecho leer el
        // estado: son permisos distintos y el del consultor es más estrecho.
        $this->conToken([ApiAbility::Provisionar])
            ->getJson(route('api.operaciones.show', $this->operacion()))
            ->assertForbidden();
    }

    public function test_no_ve_una_operacion_inexistente(): void
    {
        $this->conToken([ApiAbility::Consultar])
            ->getJson(route('api.operaciones.show', 'no-existe'))
            ->assertNotFound();
    }

    public function test_no_ve_las_operaciones_hechas_desde_la_web(): void
    {
        // Decisión D2: un token solo ve lo que él originó. Sin esto, la web y
        // las integraciones se leerían mutuamente el trabajo.
        $this->conToken([ApiAbility::Consultar])
            ->getJson(route('api.operaciones.show', $this->operacion('web')))
            ->assertNotFound();
    }

    public function test_no_devuelve_el_payload_con_la_contrasena(): void
    {
        $uuid = $this->operacion();

        $respuesta = $this->conToken([ApiAbility::Consultar])
            ->getJson(route('api.operaciones.show', $uuid))
            ->assertOk();

        // El payload va cifrado en la base pero no tiene por qué salir en la
        // respuesta: lleva la contraseña general en claro.
        $this->assertStringNotContainsString('password_general', $respuesta->getContent());
        $this->assertStringNotContainsString('Password123!', $respuesta->getContent());
        $this->assertArrayNotHasKey('payload', $respuesta->json());
    }

    public function test_el_token_de_consulta_no_puede_crear_usuarios(): void
    {
        $this->conToken([ApiAbility::Consultar])
            ->postJson('/api/usuarios/provisionar', [])
            ->assertForbidden();
    }

    public function test_devuelve_el_estado_final_cuando_la_operacion_termino(): void
    {
        $servicio = app(ProvisioningOperationService::class);
        $operacion = ProvisioningOperation::query()->where('uuid', $this->operacion())->firstOrFail();

        $servicio->registrarResultado($operacion->cuentas()->sole(), [
            'subsistema' => 'email',
            'exito' => false,
            'mensaje' => 'El subsistema no confirmó',
            'cuenta' => null,
        ]);

        // El bucle de una integración no se queda mirando indefinidamente: el
        // estado terminal y el mensaje por cuenta son lo que tiene que leer.
        $this->conToken([ApiAbility::Consultar])
            ->getJson(route('api.operaciones.show', $operacion->uuid))
            ->assertOk()
            ->assertJsonPath('terminado', true)
            ->assertJsonPath('estado', 'fallida')
            ->assertJsonPath('cuentas.0.estado', 'error')
            ->assertJsonPath('cuentas.0.mensaje', 'El subsistema no confirmó');
    }
}
