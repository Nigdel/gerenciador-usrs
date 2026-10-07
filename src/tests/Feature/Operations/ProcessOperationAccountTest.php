<?php

namespace Tests\Feature\Operations;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Jobs\ProcessOperationAccount;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\ProvisioningOperationAccount;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use App\Services\UserDataSyncService;
use App\Services\UserOffboardingService;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 3.1 — One job, one account.
 *
 * Everything here is about what the job does *not* do. The job carries only a
 * row id, so it re-reads the row every time it runs; that is what makes the
 * first two tests possible, and both guard against writing to a subsystem twice
 * — the failure that costs real accounts and leaves no trace of its own.
 *
 * The job is invoked directly rather than through `despachar()`: the tests are
 * about the execution path, not about the enqueue, and running it through the
 * queue with QUEUE_CONNECTION=sync would hide the attempt counter's behaviour
 * behind the retry machinery.
 */
class ProcessOperationAccountTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private Subsystem $email;

    private UserSubsystemAccount $cuentaEmail;

    private ProvisioningOperationService $operaciones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operaciones = app(ProvisioningOperationService::class);

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
     * The data an alta carries in the operation payload.
     *
     * The whole user is here, not just the password: each driver reads the
     * fields it needs out of this array, so a payload trimmed down to what one
     * driver uses would not be a real one.
     *
     * @return array<string, mixed>
     */
    private function payloadAlta(): array
    {
        return [
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana.silva@personal.test',
            'empresa' => 'Empresa Teste',
            'password_general' => 'Password123!',
        ];
    }

    /**
     * Builds an operation and returns it with its single row.
     *
     * @return array{0: ProvisioningOperation, 1: ProvisioningOperationAccount}
     */
    private function operacionConUnaFila(
        OperationType $tipo = OperationType::Alta,
        ?Subsystem $subsistema = null,
        array $payload = [],
    ): array {
        $operacion = $this->operaciones->describir(
            $tipo,
            $this->usuario,
            collect([$subsistema ?? $this->email]),
            $payload,
        );

        return [$operacion, $operacion->cuentas()->sole()];
    }

    /**
     * Invokes the job the way the container would inject its dependencies.
     */
    private function ejecutar(int $filaId): void
    {
        (new ProcessOperationAccount($filaId))->handle(
            $this->operaciones,
            app(UserProvisioningService::class),
            app(UserSuspensionService::class),
            app(UserOffboardingService::class),
            app(UserDataSyncService::class),
        );
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    public function test_no_hace_nada_si_la_fila_ya_no_esta_pendiente(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        [$operacion, $fila] = $this->operacionConUnaFila();

        // As if the job had already run successfully, or a retry had been
        // pushed on an account that is already correct.
        $fila->update(['estado' => OperationAccountStatus::Ok, 'mensaje' => 'Cuenta creada']);

        $this->ejecutar($fila->id);

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Ok, $fila->estado);
        $this->assertSame('Cuenta creada', $fila->mensaje);

        // The proof that the guard is what stopped it: not one request left.
        Http::assertNothingSent();

        $this->assertSame(OperationStatus::Pendiente, $operacion->fresh()->estado);
    }

    public function test_no_hace_nada_si_la_fila_ya_no_existe(): void
    {
        Http::preventStrayRequests();

        [$operacion, $fila] = $this->operacionConUnaFila();
        $fila->delete();

        // The operation was deleted (cascade) while the job waited in the queue.
        // Reporting a failure here would invent one that never happened.
        $this->ejecutar(999999);

        $this->assertSame(OperationStatus::Pendiente, $operacion->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_registra_el_fallo_si_el_usuario_ya_no_existe(): void
    {
        Http::preventStrayRequests();

        [$operacion, $fila] = $this->operacionConUnaFila();

        $this->usuario->delete();

        $this->ejecutar($fila->id);

        $fila->refresh();

        // There is nobody to give access to, and nobody to hang the state on —
        // but leaving the row pending would keep the operation 'in course'
        // forever.
        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertStringContainsString('ya no existe', (string) $fila->mensaje);
        $this->assertSame(OperationStatus::Fallida, $operacion->fresh()->estado);

        Http::assertNothingSent();
    }

    public function test_registra_el_fallo_si_la_cuenta_ya_no_existe(): void
    {
        Http::preventStrayRequests();

        [$operacion, $fila] = $this->operacionConUnaFila(OperationType::Suspension);

        // The operator deleted the account from the user page while the job
        // was queued. It is an error for that account, not for the operation.
        $this->cuentaEmail->delete();

        $this->ejecutar($fila->id);

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertStringContainsString('ya no existe', (string) $fila->mensaje);
        $this->assertSame(OperationStatus::Fallida, $operacion->fresh()->estado);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    public function test_el_alta_crea_la_cuenta_y_apunta_el_id_en_la_fila(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        [$operacion, $fila] = $this->operacionConUnaFila(
            OperationType::Alta,
            null,
            $this->payloadAlta(),
        );

        $this->ejecutar($fila->id);

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Ok, $fila->estado);

        // The account is created by the job, so the id can only arrive with
        // the result. Without this link the 'retry' button would not know
        // what to work on.
        $this->assertNotNull($fila->user_subsystem_account_id);
        $this->assertSame(
            $this->cuentaEmail->id,
            $fila->user_subsystem_account_id,
            'Debe apuntar a la cuenta que creó el propio job.',
        );

        $this->assertSame(OperationStatus::Completada, $operacion->fresh()->estado);
    }

    public function test_incrementa_intentos_antes_de_llamar_al_subsistema(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        [$operacion, $fila] = $this->operacionConUnaFila(
            OperationType::Alta,
            null,
            $this->payloadAlta(),
        );

        $this->assertSame(0, $fila->intentos);

        $this->ejecutar($fila->id);

        $this->assertSame(1, $fila->fresh()->intentos);
    }

    public function test_cada_reintento_suma_un_intento_y_conserva_el_anterior(): void
    {
        // A connection error, not a 500: a subsystem that answers with an
        // error has given a definitive 'no' and the row is marked failed on
        // the spot. What actually retries is a call that never came back.
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        [$operacion, $fila] = $this->operacionConUnaFila(
            OperationType::Alta,
            null,
            $this->payloadAlta(),
        );

        foreach (range(1, 3) as $intento) {
            try {
                $this->ejecutar($fila->id);
            } catch (ConnectionException) {
                // A connection error does not come back from handle(); it is
                // what makes the queue re-queue the job.
            }
        }

        $this->assertSame(3, $fila->fresh()->intentos);

        // The row is still pending, because nothing was resolved: leaving it
        // that way is what failed() then turns into an error.
        $this->assertSame(OperationAccountStatus::Pendiente, $fila->fresh()->estado);
        $this->assertSame(OperationStatus::Pendiente, $operacion->fresh()->estado);
    }

    public function test_el_restablecimiento_de_password_nunca_se_encola(): void
    {
        Http::preventStrayRequests();

        // If one of these ever reached the queue it could not be delivered:
        // the temporary password has to reach the operator, and a worker has
        // no channel to hand it over. The match arm says so loudly.
        $operacion = ProvisioningOperation::create([
            'gestor_user_id' => $this->usuario->id,
            'tipo' => OperationType::ResetPassword,
            'estado' => OperationStatus::Pendiente,
        ]);

        $fila = $operacion->cuentas()->create([
            'subsystem_id' => $this->email->id,
            'subsistema' => 'email',
            'estado' => OperationAccountStatus::Pendiente,
        ]);

        $this->ejecutar($fila->id);

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertStringContainsString('no se ejecuta desde la cola', (string) $fila->mensaje);
    }

    // ------------------------------------------------------------------
    // failed(): what happens when the three attempts run out
    // ------------------------------------------------------------------

    public function test_failed_deja_la_fila_en_error_y_cierra_la_operacion(): void
    {
        [$operacion, $fila] = $this->operacionConUnaFila();

        // What the worker leaves behind: the operation already 'in course' and
        // three attempts spent.
        $operacion->forceFill(['estado' => OperationStatus::EnCurso])->save();
        $fila->increment('intentos', 3);

        (new ProcessOperationAccount($fila->id))->failed(
            new RuntimeException('El subsistema email no respondió'),
        );

        $fila->refresh();
        $operacion->refresh();

        // Without this the operation stays 'in course' forever: no other job
        // is ever going to write that row, and the operator would be looking at
        // work that neither finishes nor explains itself.
        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertSame('El subsistema email no respondió', $fila->mensaje);
        $this->assertSame(OperationStatus::Fallida, $operacion->estado);
        $this->assertNotNull($operacion->terminada_at);
        $this->assertSame(3, $fila->intentos);
    }

    public function test_failed_sin_excepcion_deja_un_mensaje_util(): void
    {
        [$operacion, $fila] = $this->operacionConUnaFila();

        $operacion->forceFill(['estado' => OperationStatus::EnCurso])->save();

        (new ProcessOperationAccount($fila->id))->failed(null);

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertNotEmpty($fila->mensaje);
    }

    public function test_failed_no_hace_nada_si_la_fila_ya_se_resolvio(): void
    {
        [$operacion, $fila] = $this->operacionConUnaFila();

        // The account succeeded on the last attempt but the job still timed
        // out: overwriting the row would lose a correct result.
        $fila->update(['estado' => OperationAccountStatus::Ok, 'mensaje' => 'Cuenta creada']);

        (new ProcessOperationAccount($fila->id))->failed(new RuntimeException('Timeout'));

        $fila->refresh();

        $this->assertSame(OperationAccountStatus::Ok, $fila->estado);
        $this->assertSame('Cuenta creada', $fila->mensaje);
    }

    public function test_el_ciclo_completo_deja_la_operacion_fallida_con_su_mensaje(): void
    {
        // End to end through the three attempts and the exhaustion, which is
        // what the worker does in production.
        // A connection error, not a 500: a subsystem that answers with an
        // error has given a definitive 'no' and the row is marked failed on
        // the spot. What actually retries is a call that never came back.
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        [$operacion, $fila] = $this->operacionConUnaFila(
            OperationType::Alta,
            null,
            $this->payloadAlta(),
        );

        $job = new ProcessOperationAccount($fila->id);
        $excepcion = null;

        for ($intento = 1; $intento <= $job->tries; $intento++) {
            try {
                $job->handle(
                    $this->operaciones,
                    app(UserProvisioningService::class),
                    app(UserSuspensionService::class),
                    app(UserOffboardingService::class),
                    app(UserDataSyncService::class),
                );
            } catch (\Throwable $e) {
                $excepcion = $e;
            }
        }

        $job->failed($excepcion);

        $fila->refresh();
        $operacion->refresh();

        $this->assertSame(3, $fila->intentos);
        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertSame(OperationStatus::Fallida, $operacion->estado);
        $this->assertSame(1, $operacion->errores);
    }

    public function test_el_backoff_crece_entre_intentos(): void
    {
        $job = new ProcessOperationAccount(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff());
    }
}
