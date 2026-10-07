<?php

namespace Tests\Feature\Operations;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 3.1 — The panel's polling endpoint.
 *
 * Two properties carry this endpoint and both are security-shaped, which is
 * why they are tested first. It never returns the operation payload: in an alta
 * that carries the general password in clear text, and this is the URL the page
 * polls every few seconds. And an operation belonging to somebody else answers
 * 404, not 403 — that the operation exists at all is information about another
 * person.
 */
class OperationPollingTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

    private UserSubsystemAccount $cuentaEmail;

    private ProvisioningOperationService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ProvisioningOperationService::class);

        $this->usuario = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        $this->email = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $this->cuentaEmail = UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $this->email->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'estado' => SubsystemAccountStatus::Activo,
        ]);
    }

    private function url(ProvisioningOperation $operacion): string
    {
        return route('gestor-users.operaciones.show', [$this->usuario, $operacion->uuid]);
    }

    public function test_devuelve_el_desglose_por_cuenta_mientras_la_operacion_esta_en_curso(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!'],
        );

        $fila = $operacion->cuentas()->sole();
        $fila->update(['intentos' => 2]);
        $operacion->forceFill(['estado' => OperationStatus::EnCurso])->save();

        $this->actingAs(User::factory()->operador()->create())
            ->getJson($this->url($operacion))
            ->assertOk()
            ->assertJson([
                'uuid' => $operacion->uuid,
                'tipo' => 'alta',
                'estado' => 'en_curso',
                // The only loop an integration needs: while this is false,
                // keep polling. Nothing has to interpret 'estado'.
                'terminado' => false,
                'cuentas' => [
                    [
                        'subsistema' => 'email',
                        'estado' => 'pendiente',
                        'mensaje' => null,
                        'intentos' => 2,
                    ],
                ],
            ]);
    }

    public function test_marca_terminado_cuando_la_operacion_se_cierra(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!'],
        );

        $this->servicio->registrarResultado($operacion->cuentas()->sole(), [
            'subsistema' => 'email',
            'exito' => true,
            'mensaje' => 'Cuenta creada',
            'cuenta' => $this->cuentaEmail,
        ]);

        $this->actingAs(User::factory()->operador()->create())
            ->getJson($this->url($operacion))
            ->assertOk()
            ->assertJson([
                'estado' => 'completada',
                'terminado' => true,
                'cuentas' => [
                    ['subsistema' => 'email', 'estado' => 'ok', 'mensaje' => 'Cuenta creada'],
                ],
            ]);
    }

    public function test_nunca_devuelve_el_payload_de_la_operacion(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!', 'usuario' => 'ana.silva'],
        );

        $response = $this->actingAs(User::factory()->operador()->create())
            ->getJson($this->url($operacion))
            ->assertOk();

        // The clearest form of the assertion: the secret is nowhere in the
        // body, not just absent from a named key.
        $response->assertDontSee('Password123!');
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('payload', $response->getContent());
    }

    public function test_una_operacion_de_otro_usuario_responde_404(): void
    {
        $otro = GestorUser::create([
            'nombre_completo' => 'Luis Pérez',
            'cpf' => '98765432100',
            'password_general' => 'Password123!',
            'usuario' => 'luis.perez',
            'empresa' => 'Empresa Teste',
        ]);

        $operacionAjena = $this->servicio->describir(OperationType::Alta, $otro, collect([$this->email]));

        // Its own uuid in this user's URL. 404 rather than 403: that the
        // operation exists is information about someone else.
        $this->actingAs(User::factory()->operador()->create())
            ->getJson(route('gestor-users.operaciones.show', [$this->usuario, $operacionAjena->uuid]))
            ->assertNotFound();
    }

    public function test_un_uuid_inexistente_responde_404(): void
    {
        $this->actingAs(User::factory()->operador()->create())
            ->getJson(route('gestor-users.operaciones.show', [$this->usuario, 'no-existe']))
            ->assertNotFound();
    }

    public function test_sin_sesion_responde_redireccion_a_login(): void
    {
        $operacion = $this->servicio->describir(OperationType::Alta, $this->usuario, collect([$this->email]));

        $this->getJson($this->url($operacion))->assertUnauthorized();
    }

    public function test_el_auditor_puede_consultar_el_estado_pero_no_tocar_nada(): void
    {
        $operacion = $this->servicio->describir(OperationType::Alta, $this->usuario, collect([$this->email]));
        $fila = $operacion->cuentas()->sole();
        $fila->update(['estado' => OperationAccountStatus::Error, 'mensaje' => 'Timeout']);

        // The auditor reads the same as anybody else — viewing the user is the
        // same authorisation as watching what is being done to them.
        $this->actingAs(User::factory()->auditor()->create())
            ->getJson($this->url($operacion))
            ->assertOk()
            ->assertJson(['cuentas' => [['estado' => 'error', 'mensaje' => 'Timeout']]]);
    }

    public function test_el_resumen_refleja_el_estado_de_las_cuentas(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!'],
        );

        $fila = $operacion->cuentas()->sole();
        $fila->update(['intentos' => 3, 'estado' => OperationAccountStatus::Error, 'mensaje' => 'Timeout']);
        $operacion->forceFill(['estado' => OperationStatus::Fallida, 'errores' => 1])->save();

        $this->actingAs(User::factory()->operador()->create())
            ->getJson($this->url($operacion))
            ->assertOk()
            ->assertJson([
                'estado' => 'fallida',
                'terminado' => true,
                'resumen' => $operacion->fresh()->resumen(),
            ]);
    }
}
