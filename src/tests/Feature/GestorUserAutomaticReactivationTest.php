<?php

namespace Tests\Feature;

use App\Enums\GestorUserStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\AuditService;
use App\Services\Subsystems\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reactivación automática del gestor cuando vence su fecha de fin de
 * suspensión: el comando encola la misma operación que dispara el botón
 * manual, así que las cuentas también se reactivan.
 */
class GestorUserAutomaticReactivationTest extends TestCase
{
    use RefreshDatabase;

    private static int $contador = 0;

    private function gestorSuspendido(?string $finSuspension): GestorUser
    {
        self::$contador++;

        return GestorUser::create([
            'nombre_completo' => 'Gestor '.self::$contador,
            'cpf' => str_pad((string) self::$contador, 11, '0', STR_PAD_LEFT),
            'password_general' => 'Password123!',
            'usuario' => 'gestor'.self::$contador.'.silva',
            'empresa' => 'klios',
            'estado' => 'suspendido',
            'motivo_suspension_gestor' => 'Ausencia temporal',
            'suspendido_at' => now()->subWeek(),
            'fin_suspension_gestor' => $finSuspension,
        ]);
    }

    private function cuenta(GestorUser $gestorUser, string $slug, string $estado, int $number): UserSubsystemAccount
    {
        config(["subsystems.drivers.{$slug}" => EmailService::class]);

        $subsystem = Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => "https://{$slug}.test",
            'api_config' => [],
            'activo' => true,
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => $gestorUser->usuario,
            'external_account_id' => "account-{$number}",
            'estado' => $estado,
        ]);
    }

    public function test_reactiva_automaticamente_al_gestor_cuando_la_suspension_vencio(): void
    {
        $gestorUser = $this->gestorSuspendido(now()->subDay()->toDateTimeString());
        $cuenta = $this->cuenta($gestorUser, 'email-1', 'deshabilitado', 1);
        $gestorUser->cuentasSuspendidasPorGestor()->attach($cuenta->id);

        Http::preventStrayRequests();
        Http::fake(['https://email-1.test/*' => Http::response(['active' => true])]);

        $this->artisan('gestor-users:reactivate-expired')
            ->expectsOutputToContain('Reactivados 1 de 1 gestores.')
            ->assertExitCode(0);

        $gestorUser->refresh();
        $this->assertSame(GestorUserStatus::Activo, $gestorUser->estado);
        $this->assertNull($gestorUser->suspendido_at);
        $this->assertNull($gestorUser->motivo_suspension_gestor);
        $this->assertNull($gestorUser->fin_suspension_gestor);
        $this->assertSame(0, $gestorUser->cuentasSuspendidasPorGestor()->count());
        $this->assertSame(SubsystemAccountStatus::Activo, $cuenta->fresh()->estado);

        $operacion = ProvisioningOperation::query()->latest('id')->sole();
        $this->assertSame(OperationType::ReactivacionGestor, $operacion->tipo);
        $this->assertSame(OperationStatus::Completada, $operacion->estado);
        $this->assertSame('scheduler', $operacion->origen);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditService::USUARIO_REACTIVADO_GESTOR,
            'origen' => 'scheduler',
        ]);
    }

    public function test_no_reactiva_si_la_fecha_de_fin_todavia_no_llego(): void
    {
        $gestorUser = $this->gestorSuspendido(now()->addDay()->toDateTimeString());

        $this->artisan('gestor-users:reactivate-expired')
            ->expectsOutputToContain('No hay gestores suspendidos con la suspensión vencida.')
            ->assertExitCode(0);

        $this->assertSame(GestorUserStatus::Suspendido, $gestorUser->fresh()->estado);
        $this->assertSame(0, ProvisioningOperation::query()->count());
    }

    public function test_no_reactiva_una_suspension_sin_fecha_de_fin(): void
    {
        $gestorUser = $this->gestorSuspendido(null);

        $this->artisan('gestor-users:reactivate-expired')
            ->expectsOutputToContain('No hay gestores suspendidos con la suspensión vencida.')
            ->assertExitCode(0);

        $this->assertSame(GestorUserStatus::Suspendido, $gestorUser->fresh()->estado);
        $this->assertSame(0, ProvisioningOperation::query()->count());
    }

    public function test_dry_run_lista_sin_reactivar(): void
    {
        $gestorUser = $this->gestorSuspendido(now()->subDay()->toDateTimeString());

        $this->artisan('gestor-users:reactivate-expired', ['--dry-run' => true])
            ->expectsOutputToContain('Gestores a reactivar: 1')
            ->assertExitCode(0);

        $this->assertSame(GestorUserStatus::Suspendido, $gestorUser->fresh()->estado);
        $this->assertSame(0, ProvisioningOperation::query()->count());
    }

    public function test_un_gestor_con_operacion_en_curso_no_impide_reactivar_a_los_demas(): void
    {
        $bloqueado = $this->gestorSuspendido(now()->subDay()->toDateTimeString());
        ProvisioningOperation::create([
            'gestor_user_id' => $bloqueado->id,
            'tipo' => OperationType::ReactivacionGestor,
            'estado' => OperationStatus::EnCurso,
        ]);

        $libre = $this->gestorSuspendido(now()->subDay()->toDateTimeString());

        $this->artisan('gestor-users:reactivate-expired')
            ->expectsOutputToContain('Reactivados 1 de 2 gestores.')
            ->assertExitCode(1);

        $this->assertSame(GestorUserStatus::Suspendido, $bloqueado->fresh()->estado);
        $this->assertSame(GestorUserStatus::Activo, $libre->fresh()->estado);
    }
}
