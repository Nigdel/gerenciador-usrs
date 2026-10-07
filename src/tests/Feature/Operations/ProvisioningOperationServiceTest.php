<?php

namespace Tests\Feature\Operations;

use App\Enums\GestorUserStatus;
use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Exceptions\OperationInProgressException;
use App\Jobs\ProcessOperationAccount;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sprint 3.1 — The lifecycle of an operation, without the queue doing the work.
 *
 * Two things are tested here and they are deliberately kept apart. `describir`
 * and `cerrar` are pure state transitions: they decide what a row says and,
 * when everything is done, what the user becomes. `despachar` is the only place
 * that touches the queue, so it is the only one checked with `Queue::fake()` —
 * the point of faking it there is to see *which* jobs would be queued without
 * letting them run, which is what proves that a resolved account is never
 * queued twice.
 */
class ProvisioningOperationServiceTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

    private Subsystem $glpi;

    private UserSubsystemAccount $cuentaEmail;

    private UserSubsystemAccount $cuentaGlpi;

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

        $this->glpi = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $this->cuentaEmail = $this->cuenta($this->usuario, $this->email, 'ana.silva@empresa.test');
        $this->cuentaGlpi = $this->cuenta($this->usuario, $this->glpi, 'asilva');
    }

    private function cuenta(GestorUser $usuario, Subsystem $subsistema, string $credencial): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $usuario->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => $credencial,
            'estado' => SubsystemAccountStatus::Activo,
        ]);
    }

    // ------------------------------------------------------------------
    // describir()
    // ------------------------------------------------------------------

    public function test_describir_crea_una_fila_pendiente_por_unidad_de_trabajo(): void
    {
        // In an alta the work is a subsystem (the account does not exist yet);
        // everywhere else it is an existing account. Both must produce one row.
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $this->glpi]),
        );

        // refrescar: los contadores traen default en la migración, y un modelo
        // recién creado no los tiene en memoria hasta releerlo.
        $operacion->refresh();
        $filas = $operacion->cuentas()->orderBy('id')->get();

        $this->assertCount(2, $filas);
        $this->assertSame(['email', 'glpi'], $filas->pluck('subsistema')->all());
        $this->assertSame(
            [OperationAccountStatus::Pendiente, OperationAccountStatus::Pendiente],
            $filas->map(fn ($fila) => $fila->estado)->all(),
        );

        // In an alta the row points at the subsystem, not at an account:
        // there is nothing to point at yet.
        $this->assertSame([$this->email->id, $this->glpi->id], $filas->pluck('subsystem_id')->all());
        $this->assertSame([null, null], $filas->pluck('user_subsystem_account_id')->all());

        $this->assertSame(OperationStatus::Pendiente, $operacion->estado);
        $this->assertSame(2, $operacion->pendientes);
        $this->assertNull($operacion->terminada_at);
    }

    public function test_describir_apunta_la_cuenta_existente_en_el_resto_de_operaciones(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Suspension,
            $this->usuario,
            collect([$this->cuentaEmail]),
        );

        $fila = $operacion->cuentas()->sole();

        $this->assertSame($this->cuentaEmail->id, $fila->user_subsystem_account_id);
        $this->assertSame('email', $fila->subsistema);
    }

    public function test_una_operacion_sin_trabajo_nace_ya_completada(): void
    {
        // A suspension for someone with no accounts anywhere: there is nothing
        // to queue, so leaving the operation 'pendiente' would block the user
        // forever with a job that is never going to arrive.
        $operacion = $this->servicio->describir(OperationType::Suspension, $this->usuario, collect());
        $operacion->refresh();

        $this->assertSame(OperationStatus::Completada, $operacion->estado);
        $this->assertSame(0, $operacion->pendientes);
        $this->assertNotNull($operacion->terminada_at);
        $this->assertCount(0, $operacion->cuentas);
    }

    public function test_describir_rechaza_una_segunda_operacion_abierta_del_mismo_usuario(): void
    {
        $this->servicio->describir(OperationType::Alta, $this->usuario, collect([$this->email]));

        $this->expectException(OperationInProgressException::class);

        $this->servicio->describir(OperationType::Suspension, $this->usuario, collect([$this->cuentaEmail]));
    }

    public function test_una_operacion_ya_terminada_no_bloquea_la_siguiente(): void
    {
        $primera = $this->servicio->describir(OperationType::Alta, $this->usuario, collect([$this->email]));
        $primera->forceFill([
            'estado' => OperationStatus::Completada,
            'exitos' => 1,
            'pendientes' => 0,
        ])->save();

        $segunda = $this->servicio->describir(OperationType::Suspension, $this->usuario, collect([$this->cuentaEmail]));

        $this->assertSame(OperationStatus::Pendiente, $segunda->estado);
    }

    // ------------------------------------------------------------------
    // despachar()
    // ------------------------------------------------------------------

    public function test_despachar_encola_un_job_por_fila_pendiente_en_la_cola_de_subsistemas(): void
    {
        Queue::fake();

        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $this->glpi]),
        );

        $this->servicio->despachar($operacion);

        Queue::assertPushed(ProcessOperationAccount::class, 2);
        Queue::assertPushedOn(ProvisioningOperationService::COLA, ProcessOperationAccount::class);
        Queue::assertPushed(
            ProcessOperationAccount::class,
            fn (ProcessOperationAccount $job) => $job->filaId === $operacion->cuentas()->orderBy('id')->first()->id,
        );

        $this->assertSame(OperationStatus::EnCurso, $operacion->fresh()->estado);
    }

    public function test_despachar_no_vuelve_a_encolar_lo_que_ya_esta_resuelto(): void
    {
        // The same call can be made twice on one operation (a retry). The
        // second time, a resolved row must not go back to the queue: running it
        // again would be a second write to the same subsystem account.
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $this->glpi]),
        );

        $filas = $operacion->cuentas()->orderBy('id')->get();
        $this->servicio->registrarResultado($filas[0], [
            'subsistema' => 'email',
            'exito' => true,
            'mensaje' => 'Cuenta creada',
            'cuenta' => $this->cuentaEmail,
        ]);

        // Describir() no despacha; solo despachar() mueve la cabecera a 'en
        // curso'. Sin encolar nada, la operación sigue pendiente aunque una de
        // sus filas ya esté resuelta.
        $this->assertSame(OperationStatus::Pendiente, $operacion->fresh()->estado);

        Queue::fake();
        $this->servicio->despachar($operacion);

        $this->assertSame(OperationStatus::EnCurso, $operacion->fresh()->estado);
        Queue::assertPushed(ProcessOperationAccount::class, 1);
        Queue::assertPushed(
            ProcessOperationAccount::class,
            fn (ProcessOperationAccount $job) => $job->filaId === $filas[1]->id,
        );
    }

    public function test_una_operacion_sin_pendientes_no_encola_nada(): void
    {
        Queue::fake();

        $operacion = $this->servicio->describir(OperationType::Suspension, $this->usuario, collect());

        $this->servicio->despachar($operacion);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // cerrar()
    // ------------------------------------------------------------------

    public function test_cerrar_no_hace_nada_mientras_quede_una_cuenta_pendiente(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $this->glpi]),
        );

        $filas = $operacion->cuentas()->orderBy('id')->get();

        $this->servicio->registrarResultado($filas[0], [
            'subsistema' => 'email',
            'exito' => true,
            'mensaje' => 'Cuenta creada',
            'cuenta' => $this->cuentaEmail,
        ]);

        $operacion->refresh();

        // Queda una fila pendiente, así que la operación no se cierra. Y como
        // nunca se despachó, la cabecera sigue en 'pendiente' — el estado pasa
        // a 'en curso' cuando algo sale a la cola, no antes.
        $this->assertSame(OperationStatus::Pendiente, $operacion->estado);
        $this->assertNull($operacion->terminada_at);
        $this->assertSame(OperationAccountStatus::Pendiente, $filas[1]->fresh()->estado);
    }

    public function test_cerrar_recuenta_las_filas_y_deja_la_operacion_completada(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $this->glpi]),
        );

        $filas = $operacion->cuentas()->orderBy('id')->get();

        $this->servicio->registrarResultado($filas[0], [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta creada', 'cuenta' => $this->cuentaEmail,
        ]);
        $this->servicio->registrarResultado($filas[1], [
            'subsistema' => 'glpi', 'exito' => true, 'mensaje' => 'Cuenta creada', 'cuenta' => $this->cuentaGlpi,
        ]);

        $operacion->refresh();

        $this->assertSame(OperationStatus::Completada, $operacion->estado);
        $this->assertSame(2, $operacion->exitos);
        $this->assertSame(0, $operacion->errores);
        $this->assertSame(0, $operacion->pendientes);
        $this->assertNotNull($operacion->terminada_at);
    }

    public function test_cerrar_deja_la_operacion_fallida_en_cuanto_una_cuenta_falla(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email, $this->glpi]),
        );

        $filas = $operacion->cuentas()->orderBy('id')->get();

        $this->servicio->registrarResultado($filas[0], [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta creada', 'cuenta' => $this->cuentaEmail,
        ]);
        $this->servicio->registrarResultado($filas[1], [
            'subsistema' => 'glpi', 'exito' => false, 'mensaje' => 'Timeout', 'cuenta' => null,
        ]);

        $operacion->refresh();

        $this->assertSame(OperationStatus::Fallida, $operacion->estado);
        $this->assertSame(1, $operacion->exitos);
        $this->assertSame(1, $operacion->errores);
        $this->assertNotNull($operacion->terminada_at);
    }

    // ------------------------------------------------------------------
    // The state of the user on close (private, so tested through cerrar())
    // ------------------------------------------------------------------

    public function test_una_baja_sin_errores_marca_al_usuario_como_baja(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Baja,
            $this->usuario,
            collect([$this->cuentaEmail, $this->cuentaGlpi]),
            ['motivo_baja' => 'Fin del contrato'],
        );

        foreach ($operacion->cuentas()->orderBy('id')->get() as $fila) {
            $this->servicio->registrarResultado($fila, [
                'subsistema' => $fila->subsistema,
                'exito' => true,
                'mensaje' => 'Cuenta dada de baja',
                'cuenta' => $fila->cuenta,
            ]);
        }

        $usuario = $this->usuario->fresh();

        $this->assertSame(GestorUserStatus::Baja, $usuario->estado);
        $this->assertNotNull($usuario->baja_at);
        $this->assertSame('Fin del contrato', $usuario->motivo_baja);
    }

    public function test_una_baja_con_errores_no_marca_al_usuario_como_baja(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Baja,
            $this->usuario,
            collect([$this->cuentaEmail, $this->cuentaGlpi]),
            ['motivo_baja' => 'Fin del contrato'],
        );

        $filas = $operacion->cuentas()->orderBy('id')->get();

        $this->servicio->registrarResultado($filas[0], [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta dada de baja', 'cuenta' => $this->cuentaEmail,
        ]);
        $this->servicio->registrarResultado($filas[1], [
            'subsistema' => 'glpi', 'exito' => false, 'mensaje' => 'Timeout', 'cuenta' => null,
        ]);

        // This is the whole reason the state is applied in cerrar() and not in
        // the job: one account is still live, so the user cannot be offboarded.
        $this->assertNotSame(GestorUserStatus::Baja, $this->usuario->fresh()->estado);
        $this->assertSame(OperationStatus::Fallida, $operacion->fresh()->estado);
    }

    public function test_una_reactivacion_sin_errores_devuelve_al_usuario_a_activo(): void
    {
        $this->usuario->update([
            'estado' => GestorUserStatus::Baja,
            'baja_at' => now()->subYear(),
            'motivo_baja' => 'Fin del contrato',
        ]);

        $operacion = $this->servicio->describir(
            OperationType::Reactivacion,
            $this->usuario->fresh(),
            collect([$this->cuentaEmail]),
        );

        $fila = $operacion->cuentas()->sole();
        $this->servicio->registrarResultado($fila, [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta reactivada', 'cuenta' => $this->cuentaEmail,
        ]);

        $usuario = $this->usuario->fresh();

        $this->assertSame(GestorUserStatus::Activo, $usuario->estado);
        $this->assertNull($usuario->baja_at);
        $this->assertNull($usuario->motivo_baja);
    }

    public function test_una_suspension_no_cambia_el_estado_del_usuario(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Suspension,
            $this->usuario,
            collect([$this->cuentaEmail]),
            ['motivo_suspension' => 'Licencia médica'],
        );

        $fila = $operacion->cuentas()->sole();
        $this->servicio->registrarResultado($fila, [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta suspendida', 'cuenta' => $this->cuentaEmail,
        ]);

        // Only a baja and a reactivation move the user; a suspension that
        // worked perfectly must not turn into a 'baja'.
        $this->assertSame(GestorUserStatus::Activo, $this->usuario->fresh()->estado);
        $this->assertSame(OperationStatus::Completada, $operacion->fresh()->estado);
    }

    // ------------------------------------------------------------------
    // registrarFallo()
    // ------------------------------------------------------------------

    public function test_registrar_fallo_deja_la_fila_en_error_y_cierra_la_operacion(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
        );

        $fila = $operacion->cuentas()->sole();
        $this->servicio->registrarFallo($fila, 'El trabajo agotó sus reintentos.');

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertSame('El trabajo agotó sus reintentos.', $fila->mensaje);

        // Without this the operation would stay 'en curso' forever: nothing else
        // is ever going to write that row again.
        $this->assertSame(OperationStatus::Fallida, $operacion->fresh()->estado);
        $this->assertNotNull($operacion->fresh()->terminada_at);
    }

    public function test_una_operacion_completada_poda_el_password_general(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!'],
        );

        $fila = $operacion->cuentas()->sole();
        $this->servicio->registrarResultado($fila, [
            'subsistema' => 'email', 'exito' => true, 'mensaje' => 'Cuenta creada', 'cuenta' => $this->cuentaEmail,
        ]);

        // Nobody has to be given access any more, so the secret goes with the
        // operation instead of waiting for the scheduled prune.
        $this->assertArrayNotHasKey('password_general', $operacion->fresh()->payload ?? []);
    }

    public function test_una_operacion_fallida_conserva_el_password_para_el_reintento(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!'],
        );

        $fila = $operacion->cuentas()->sole();
        $this->servicio->registrarFallo($fila, 'Timeout');

        // A retry still needs it, so this one keeps the secret until the prune.
        $this->assertSame('Password123!', $operacion->fresh()->payload['password_general']);
    }

    // ------------------------------------------------------------------
    // serializar()
    // ------------------------------------------------------------------

    public function test_serializar_nunca_devuelve_el_payload(): void
    {
        $operacion = $this->servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([$this->email]),
            ['password_general' => 'Password123!', 'usuario' => 'ana.silva'],
        );

        $json = json_encode($this->servicio->serializar($operacion), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Password123!', $json);
        $this->assertStringNotContainsString('payload', $json);
        $this->assertStringContainsString($operacion->uuid, $json);
    }

    public function test_una_fila_borrada_no_deja_la_operacion_colgada(): void
    {
        // The job looks the row up by id and gives up when it is gone. This is
        // the operation-level counterpart: an operation with no rows at all has
        // nothing left to resolve.
        $operacion = ProvisioningOperation::create([
            'gestor_user_id' => $this->usuario->id,
            'tipo' => OperationType::Alta,
            'estado' => OperationStatus::EnCurso,
        ]);

        $this->servicio->cerrar($operacion);

        $this->assertSame(OperationStatus::Completada, $operacion->fresh()->estado);
    }
}
