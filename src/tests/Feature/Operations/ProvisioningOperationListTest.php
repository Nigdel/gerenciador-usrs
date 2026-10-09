<?php

namespace Tests\Feature\Operations;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 4 — Listado de operaciones y ficha de una operación.
 *
 * La ficha es la vista donde se mira por qué una acción no terminó. Su
 * promesa es que enseña lo ocurrido y nada más: los mensajes de error del
 * subsistema sí, el payload con la contraseña general en claro no. Por eso el
 * test de la ficha comprueba que el cuerpo no aparece.
 */
class ProvisioningOperationListTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

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
    }

    private function operacion(OperationType $tipo = OperationType::Alta, ?OperationStatus $estado = null)
    {
        $operacion = $this->servicio->describir(
            $tipo,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!'],
        );

        if ($estado) {
            $operacion->forceFill(['estado' => $estado])->save();
        }

        return $operacion->fresh();
    }

    public function test_el_listado_muestra_las_operaciones(): void
    {
        $operacion = $this->operacion();

        $this->actingAs(User::factory()->operador()->create())
            ->get(route('operaciones.index'))
            ->assertOk()
            ->assertSee('Operações de Provisionamento')
            ->assertSee('Alta')
            ->assertSee('Ana Silva');
    }

    public function test_el_listado_filtra_por_usuario(): void
    {
        $this->operacion();

        $otro = GestorUser::create([
            'nombre_completo' => 'Bruno Costa',
            'cpf' => '98765432100',
            'password_general' => 'Password123!',
            'usuario' => 'bruno.costa',
            'empresa' => 'Empresa Teste',
        ]);

        $this->servicio->describir(
            OperationType::Baja,
            $otro,
            collect([$this->email]),
            [],
        );

        $this->actingAs(User::factory()->operador()->create())
            ->get(route('operaciones.index', ['usuario' => 'Ana']))
            ->assertOk()
            ->assertSee('Ana Silva')
            ->assertDontSee('Bruno Costa');
    }

    public function test_la_ficha_muestra_el_desglose_por_cuenta(): void
    {
        $operacion = $this->operacion(OperationType::Alta, OperationStatus::Fallida);
        $fila = $operacion->cuentas()->sole();
        $fila->update(['estado' => OperationAccountStatus::Error, 'mensaje' => 'El dominio no acepta el alias']);

        $this->actingAs(User::factory()->operador()->create())
            ->get(route('operaciones.show', $operacion))
            ->assertOk()
            ->assertSee('Ana Silva')
            ->assertSee('email')
            ->assertSee('El dominio no acepta el alias');
    }

    public function test_la_ficha_no_muestra_el_payload_con_la_contrasena(): void
    {
        $operacion = $this->operacion();

        $contenido = $this->actingAs(User::factory()->operador()->create())
            ->get(route('operaciones.show', $operacion))
            ->assertOk()
            ->getContent();

        // La contraseña general viaja en claro en el payload de un alta. La
        // ficha no la pinta: se ve el resultado, no el intento.
        $this->assertStringNotContainsString('Password123!', $contenido);
    }
}
