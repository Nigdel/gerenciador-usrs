<?php

namespace Tests\Feature;

use App\Enums\GestorUserStatus;
use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\ProvisioningOperationAccount;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 1.2 — Reintentar una cuenta que falló.
 *
 * Lo que más importa aquí no es que el reintento funcione, sino que NO repita
 * trabajo que ya se hizo: si al reintentar una operación de dos cuentas se
 * volvieran a encolar las dos, la que ya estaba correcta se volvería a tocar en
 * el subsistema. Por eso hay un test explícito de eso y otro de que la baja se
 * completa al reintentarse, que es el caso donde el estado del usuario depende
 * de que todas las cuentas salgan bien.
 */
class RetryOperationAccountTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

    private UserSubsystemAccount $cuentaEmail;

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

    /**
     * Crea una operación de un tipo dado sobre las cuentas indicadas y la deja
     * en el estado que pida cada test.
     *
     * @param  array<int, string>  $exitoPorCuenta  true/false por cuenta, en el mismo orden.
     * @return array{0: ProvisioningOperation, 1: Collection}
     */
    private function operacionTerminada(OperationType $tipo, array $exitoPorCuenta): array
    {
        $servicio = app(ProvisioningOperationService::class);
        $cuentas = collect([$this->cuentaEmail]);

        $operacion = $servicio->describir($tipo, $this->usuario, $cuentas, [
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
        ]);

        foreach ($operacion->cuentas as $i => $fila) {
            $servicio->registrarResultado($fila, [
                'subsistema' => 'email',
                'exito' => $exitoPorCuenta[$i] ?? false,
                'mensaje' => $exitoPorCuenta[$i] ?? false ? 'Cuenta creada' : 'El subsistema no confirmó',
                'cuenta' => $this->cuentaEmail,
            ]);
        }

        return [$operacion->fresh(), $operacion->cuentas()->orderBy('id')->get()];
    }

    private function urlRetry($operacion, ProvisioningOperationAccount $fila): string
    {
        return route('gestor-users.operaciones.retry', [
            $this->usuario,
            $operacion->uuid,
            $fila->id,
        ]);
    }

    public function test_reintenta_una_cuenta_que_termino_con_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        [$operacion, $filas] = $this->operacionTerminada(OperationType::Alta, [false]);
        $fila = $filas->sole();

        $this->assertSame(OperationStatus::Fallida, $operacion->estado);

        $this->actingAs(User::factory()->admin()->create())
            ->post($this->urlRetry($operacion, $fila))
            ->assertRedirect(route('gestor-users.show', $this->usuario))
            ->assertSessionHas('success');

        // Con QUEUE_CONNECTION=sync el reintento sale dentro de la petición y
        // ya ha terminado: lo que se comprueba es que pasó de error a correcto
        // y que la operación quedó completada.
        $this->assertSame(OperationAccountStatus::Ok, $fila->fresh()->estado);
        $this->assertSame(OperationStatus::Completada, $operacion->fresh()->estado);
        $this->assertSame(1, $fila->fresh()->intentos, 'El reintento cuenta como un intento más.');
    }

    public function test_no_reintenta_una_cuenta_que_ya_esta_correcta(): void
    {
        [$operacion, $filas] = $this->operacionTerminada(OperationType::Alta, [true]);
        $fila = $filas->sole();

        $this->actingAs(User::factory()->admin()->create())
            ->post($this->urlRetry($operacion, $fila))
            ->assertRedirect(route('gestor-users.show', $this->usuario))
            ->assertSessionHas('error');

        // El motivo importa tanto como el código: reintentar una cuenta correcta
        // tocaría el subsistema por segunda vez.
        $this->assertSame(OperationAccountStatus::Ok, $fila->fresh()->estado);
    }

    public function test_el_operador_no_puede_reintentar_una_baja(): void
    {
        [$operacion, $filas] = $this->operacionTerminada(OperationType::Baja, [false]);

        // El operador puede suspender, pero dar de baja es de admin: el
        // reintento de una baja no puede ser una puerta trasera.
        $this->actingAs(User::factory()->operador()->create())
            ->post($this->urlRetry($operacion, $filas->sole()))
            ->assertForbidden();

        $this->assertSame(OperationAccountStatus::Error, $filas->sole()->fresh()->estado);
    }

    public function test_una_fila_de_otra_operacion_no_se_puede_reintentar_desde_esta_url(): void
    {
        [$operacion] = $this->operacionTerminada(OperationType::Alta, [false]);

        // Una operación aparte, de otro usuario: su fila no debe poder
        // reintentarse colando el uuid de la primera en la URL.
        $otro = GestorUser::create([
            'nombre_completo' => 'Luis Pérez',
            'cpf' => '98765432100',
            'password_general' => 'Password123!',
            'usuario' => 'luis.perez',
            'empresa' => 'Empresa Teste',
        ]);

        $servicio = app(ProvisioningOperationService::class);
        $filaAjena = $servicio->describir(OperationType::Alta, $otro, collect([$this->email]), [])
            ->cuentas()
            ->sole();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('gestor-users.operaciones.retry', [
                $this->usuario,
                $operacion->uuid,
                $filaAjena->id,
            ]))
            ->assertNotFound();

        $this->assertSame(OperationAccountStatus::Pendiente, $filaAjena->fresh()->estado);
    }

    public function test_reintentar_no_vuelve_a_encolar_las_cuentas_que_ya_estaban_correctas(): void
    {
        // Dos cuentas: una correcta y otra con error. El caso que motiva todo
        // el método — reintentar solo debe tocar la que falló.
        $glpi = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $cuentaGlpi = UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $glpi->id,
            'credencial_usuario' => 'aperez',
            'estado' => SubsystemAccountStatus::Activo,
        ]);

        // Con QUEUE_CONNECTION=sync el reintento sale a los subsistemas de
        // verdad, así que se fakean los dos: lo que se comprueba es que solo
        // se toque el que falló.
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response(['id' => 'email-42']),
            'https://glpi.test/*' => Http::response(['id' => 1]),
        ]);

        $servicio = app(ProvisioningOperationService::class);
        $operacion = $servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $glpi]),
            [
                'password_general' => 'Password123!',
                'usuario' => 'ana.silva',
                'nombre_completo' => 'Ana Silva',
                'cpf' => '12345678901',
            ],
        );

        $filas = $operacion->cuentas()->orderBy('id')->get();
        $servicio->registrarResultado($filas[0], [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta creada', 'cuenta' => $this->cuentaEmail,
        ]);
        $servicio->registrarResultado($filas[1], [
            'subsistema' => 'glpi', 'exito' => false, 'mensaje' => 'Timeout', 'cuenta' => null,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post($this->urlRetry($operacion->fresh(), $filas[1]))
            ->assertSessionHas('success');

        // La que ya estaba bien no se ha vuelto a tocar: es el motivo de que
        // reintentar() encole solo la fila que falló.
        $this->assertSame(OperationAccountStatus::Ok, $filas[0]->fresh()->estado);
        // Y la que falló se ha resuelto al reintentarse (sync la ejecuta ya).
        $this->assertSame(OperationAccountStatus::Ok, $filas[1]->fresh()->estado);

        // La operación pasa a completada: ya no le queda nada pendiente.
        $this->assertSame(OperationStatus::Completada, $operacion->fresh()->estado);
        $this->assertSame(0, $operacion->fresh()->errores);

        // Prueba directa de que el subsistema que ya estaba bien no se volvió
        // a tocar: ninguna petición salió hacia email. El conteo absoluto no
        // sirve aquí porque el alta de GLPI hace más de una llamada.
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://email.test/'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://glpi.test/'));

        $this->assertNotNull($cuentaGlpi->id);
    }

    public function test_la_baja_se_completa_al_reintentar_la_cuenta_que_fallo(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/*' => Http::response(['active' => false]),
        ]);

        $this->actingAs(User::factory()->admin()->create());

        [$operacion, $filas] = $this->operacionTerminada(OperationType::Baja, [false]);
        $fila = $filas->sole();

        // La baja solo marca al usuario cuando la operación cierra sin errores.
        $this->assertNotSame(GestorUserStatus::Baja, $this->usuario->fresh()->estado);

        $this->post($this->urlRetry($operacion, $fila))->assertSessionHas('success');

        // Con QUEUE_CONNECTION=sync el reintento ya se ejecutó entero.
        $this->assertSame(OperationStatus::Completada, $operacion->fresh()->estado);
        $this->assertSame(GestorUserStatus::Baja, $this->usuario->fresh()->estado);
    }

    public function test_el_auditor_no_ve_el_boton_de_reintentar(): void
    {
        [$operacion, $filas] = $this->operacionTerminada(OperationType::Alta, [false]);

        $this->actingAs(User::factory()->auditor()->create())
            ->get(route('gestor-users.show', $this->usuario))
            ->assertOk()
            ->assertDontSee('Reintentar');

        // Y aunque se pida la URL a mano, tampoco puede.
        $this->post($this->urlRetry($operacion, $filas->sole()))->assertForbidden();
    }

    public function test_no_se_puede_reintentar_mientras_la_operacion_sigue_en_curso(): void
    {
        $servicio = app(ProvisioningOperationService::class);
        $operacion = $servicio->describir(OperationType::Alta, $this->usuario, collect([$this->email]), []);
        $fila = $operacion->cuentas()->sole();

        // Una fila con error dentro de una operación abierta solo puede venir de
        // un job que falló y dejó el resto pendiente; reintentarla saltaría el
        // orden en que se van resolviendo las demás.
        $fila->update(['estado' => OperationAccountStatus::Error, 'mensaje' => 'Fallo']);

        $this->actingAs(User::factory()->admin()->create())
            ->post($this->urlRetry($operacion, $fila))
            ->assertSessionHas('error');

        $this->assertSame(OperationAccountStatus::Error, $fila->fresh()->estado);
    }
}
