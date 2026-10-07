<?php

namespace Tests\Feature;

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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sprint 1.1 — Caducidad de la contraseña general en el payload.
 *
 * El payload de la operación va cifrado, pero cifrado no es lo mismo que
 * inocuo: guarda la contraseña en claro mientras la operación exista. Estos
 * tests fijan las dos reglas que la protegen —al completarse se borra ya, y
 * pasado el TTL se borra lo que quede— y sobre todo el motivo de que sea la
 * primera y no la única: una fallida sin contraseña no se puede reintentar.
 */
class OperationSecretsTest extends TestCase
{
    use RefreshDatabase;

    private GestorUser $usuario;

    private UserSubsystemAccount $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Empresa Teste',
        ]);

        $subsistema = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'activo' => true,
        ]);

        $this->cuenta = UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'estado' => SubsystemAccountStatus::Activo,
        ]);
    }

    /**
     * Crea una operación de alta con la contraseña en el payload y una fila de
     * trabajo, y devuelve ambos.
     *
     * @return array{0: ProvisioningOperation, 1: ProvisioningOperationAccount}
     */
    private function alta(string $contrasena = 'Password123!'): array
    {
        $servicio = app(ProvisioningOperationService::class);

        $operacion = $servicio->describir(
            OperationType::Alta,
            $this->usuario,
            collect([Subsystem::query()->find($this->cuenta->subsystem_id)]),
            ['password_general' => $contrasena, 'usuario' => 'ana.silva'],
        );

        return [$operacion, $operacion->cuentas()->sole()];
    }

    public function test_una_operacion_completada_no_conserva_la_contrasena_general(): void
    {
        [$operacion] = $this->alta();

        $this->assertArrayHasKey('password_general', $operacion->fresh()->payload);

        // Todas las cuentas salen bien: la operación se cierra sin errores.
        $servicio = app(ProvisioningOperationService::class);
        $servicio->registrarResultado($operacion->cuentas()->sole(), [
            'subsistema' => 'email',
            'exito' => true,
            'mensaje' => 'Cuenta creada',
            'cuenta' => $this->cuenta,
        ]);

        $operacion->refresh();

        $this->assertSame(OperationStatus::Completada, $operacion->estado);
        $this->assertArrayNotHasKey('password_general', $operacion->payload);
        // Lo demás del payload sobrevive: no es el payload entero lo que se va.
        $this->assertSame('ana.silva', $operacion->payload['usuario']);
    }

    public function test_una_operacion_fallida_conserva_la_contrasena_para_poder_reintentar(): void
    {
        [$operacion] = $this->alta();

        app(ProvisioningOperationService::class)->registrarResultado($operacion->cuentas()->sole(), [
            'subsistema' => 'email',
            'exito' => false,
            'mensaje' => 'El subsistema no confirmó',
            'cuenta' => null,
        ]);

        $operacion->refresh();

        $this->assertSame(OperationStatus::Fallida, $operacion->estado);
        // Es justo lo que necesita el reintento del Sprint 1.2 para volver a
        // crear la cuenta: si se perdiera aquí, habría que resetear a mano.
        $this->assertSame('Password123!', $operacion->payload['password_general']);
    }

    public function test_el_prune_respeta_el_ttl(): void
    {
        config()->set('operations.secret_ttl_hours', 72);
        Carbon::setTestNow('2026-10-07 12:00:00');

        [$reciente] = $this->alta();

        // Dos altas seguidas sobre el mismo usuario no conviven (Sprint 1.4),
        // así que la primera se termina antes de abrir la segunda.
        $reciente->forceFill([
            'estado' => OperationStatus::Completada,
            'terminada_at' => now(),
        ])->save();

        [$vieja] = $this->alta();

        // La vieja se terminó hace 100 h: el TTL de 72 h ya venció.
        $vieja->forceFill([
            'estado' => OperationStatus::Completada,
            'terminada_at' => Carbon::now()->subHours(100),
        ])->save();

        $this->artisan('operations:prune-secrets')->assertSuccessful();

        $this->assertArrayNotHasKey('password_general', $vieja->fresh()->payload);
        // La que aún no venció se queda como estaba.
        $this->assertSame('Password123!', $reciente->fresh()->payload['password_general']);
    }

    public function test_el_prune_no_toca_las_operaciones_sin_contrasena(): void
    {
        config()->set('operations.secret_ttl_hours', 72);
        Carbon::setTestNow('2026-10-07 12:00:00');

        // Una suspensión: su payload nunca lleva contraseña general.
        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Suspension,
            $this->usuario,
            collect([$this->cuenta]),
            ['motivo_suspension' => 'Fin de contrato'],
        );

        $operacion->forceFill([
            'estado' => OperationStatus::Completada,
            'terminada_at' => Carbon::now()->subHours(500),
        ])->save();

        $this->artisan('operations:prune-secrets')->assertSuccessful();

        // El motivo sigue ahí: podar no es vaciar el payload.
        $this->assertSame('Fin de contrato', $operacion->fresh()->payload['motivo_suspension']);
    }

    public function test_el_prune_respeta_el_dry_run(): void
    {
        config()->set('operations.secret_ttl_hours', 72);
        Carbon::setTestNow('2026-10-07 12:00:00');

        [$operacion] = $this->alta();
        $operacion->forceFill([
            'estado' => OperationStatus::Completada,
            'terminada_at' => Carbon::now()->subHours(100),
        ])->save();

        $this->artisan('operations:prune-secrets --dry-run')
            ->expectsOutputToContain('1 operación(es) se limpiarían')
            ->assertSuccessful();

        $this->assertSame('Password123!', $operacion->fresh()->payload['password_general']);
    }

    public function test_una_operacion_sin_trabajo_se_completa_sin_filas(): void
    {
        // El caso límite: sin cuentas no hay nada que ejecutar, pero la
        // operación existe y debe quedar cerrada y limpia igual.
        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Alta,
            $this->usuario,
            collect(),
            ['password_general' => 'Password123!'],
        );

        $this->assertSame(OperationStatus::Completada, $operacion->estado);
        $this->assertSame(0, ProvisioningOperationAccount::query()->count());
    }

    public function test_el_actor_se_congela_en_la_operacion(): void
    {
        // No va de secretos, pero es lo que hace la operación legible para
        // siempre y conviene que quede fijado aquí, en el mismo sitio donde se
        // crea el registro que lo va a guardar.
        $this->actingAs(User::factory()->admin()->create());

        [$operacion] = $this->alta();

        $this->assertNotNull($operacion->actor_id);
        $this->assertNotEmpty($operacion->actor_nombre);
    }

    public function test_la_fila_que_falla_queda_con_error_y_la_operacion_fallida(): void
    {
        [$operacion, $fila] = $this->alta();

        app(ProvisioningOperationService::class)->registrarResultado($fila, [
            'subsistema' => 'email',
            'exito' => false,
            'mensaje' => 'Timeout',
            'cuenta' => null,
        ]);

        $fila->refresh();
        $operacion->refresh();

        $this->assertSame(OperationAccountStatus::Error, $fila->estado);
        $this->assertSame(1, $operacion->errores);
        $this->assertSame(0, $operacion->pendientes);
        $this->assertNotNull($operacion->terminada_at);
    }
}
