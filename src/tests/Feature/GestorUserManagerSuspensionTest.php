<?php

namespace Tests\Feature;

use App\Enums\GestorUserStatus;
use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\ProvisioningOperationAccount;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use App\Services\Subsystems\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorUserManagerSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function gestor(): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);
    }

    private function cuenta(
        GestorUser $gestorUser,
        string $slug,
        string $estado,
        int $number,
    ): UserSubsystemAccount {
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

    public function test_suspender_y_reactivar_el_gestor_solo_cambia_las_cuentas_que_estaban_activas(): void
    {
        $gestorUser = $this->gestor();
        $activas = collect([
            $this->cuenta($gestorUser, 'email-1', 'activo', 1),
            $this->cuenta($gestorUser, 'email-2', 'activo', 2),
            $this->cuenta($gestorUser, 'email-3', 'activo', 3),
        ]);
        $yaSuspendida = $this->cuenta($gestorUser, 'email-4', 'suspendido', 4);
        $yaDeshabilitada = $this->cuenta($gestorUser, 'email-5', 'deshabilitado', 5);

        Http::preventStrayRequests();
        Http::fake([
            'https://email-1.test/*' => Http::sequence()
                ->push(['active' => false])
                ->push(['active' => false])
                ->push(['active' => true])
                ->push(['active' => true]),
            'https://email-2.test/*' => Http::sequence()
                ->push(['active' => false])
                ->push(['active' => false])
                ->push(['active' => true])
                ->push(['active' => true]),
            'https://email-3.test/*' => Http::sequence()
                ->push(['active' => false])
                ->push(['active' => false])
                ->push(['active' => true])
                ->push(['active' => true]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend-manager', $gestorUser), [
            'motivo_suspension_gestor' => 'Ausencia temporal',
            'fin_suspension_gestor' => now()->addMonth()->toDateString(),
        ])->assertRedirect(route('gestor-users.show', $gestorUser));

        $this->assertSame(GestorUserStatus::Suspendido, $gestorUser->fresh()->estado);
        $this->assertSame('Ausencia temporal', $gestorUser->fresh()->motivo_suspension_gestor);
        $this->assertSame(now()->addMonth()->toDateString(), $gestorUser->fresh()->fin_suspension_gestor->toDateString());
        $this->assertSame(3, $gestorUser->cuentasSuspendidasPorGestor()->count());
        $this->assertSame(
            [SubsystemAccountStatus::Deshabilitado, SubsystemAccountStatus::Deshabilitado, SubsystemAccountStatus::Deshabilitado],
            $activas->map(fn (UserSubsystemAccount $account) => $account->fresh()->estado)->all(),
        );
        $this->assertSame(SubsystemAccountStatus::Suspendido, $yaSuspendida->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Deshabilitado, $yaDeshabilitada->fresh()->estado);
        $this->get(route('gestor-users.show', $gestorUser))
            ->assertOk()
            ->assertSee('Gestor suspendido')
            ->assertSee($gestorUser->fresh()->fin_suspension_gestor->format('d/m/Y'))
            ->assertSee('Reactivar gestor');

        $this->post(route('gestor-users.reactivate-manager', $gestorUser))
            ->assertRedirect(route('gestor-users.show', $gestorUser));

        $reactivacion = ProvisioningOperation::query()->latest('id')->first();
        $this->assertSame(OperationStatus::Completada, $reactivacion->estado, $reactivacion->cuentas()->pluck('mensaje')->implode(' | '));
        $this->assertSame(GestorUserStatus::Activo, $gestorUser->fresh()->estado);
        $this->assertNull($gestorUser->fresh()->suspendido_at);
        $this->assertNull($gestorUser->fresh()->motivo_suspension_gestor);
        $this->assertNull($gestorUser->fresh()->fin_suspension_gestor);
        $this->assertSame(0, $gestorUser->cuentasSuspendidasPorGestor()->count());
        $this->assertSame(
            [SubsystemAccountStatus::Activo, SubsystemAccountStatus::Activo, SubsystemAccountStatus::Activo],
            $activas->map(fn (UserSubsystemAccount $account) => $account->fresh()->estado)->all(),
        );
        $this->assertSame(SubsystemAccountStatus::Suspendido, $yaSuspendida->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Deshabilitado, $yaDeshabilitada->fresh()->estado);

        $this->get(route('gestor-users.show', $gestorUser))
            ->assertOk()
            ->assertDontSee('Gestor suspendido');
    }

    public function test_estado_del_gestor_no_cambia_si_falla_una_cuenta_y_se_conserva_la_captura_para_reintentar(): void
    {
        $gestorUser = $this->gestor();
        $cuentaOk = $this->cuenta($gestorUser, 'email-1', 'activo', 1);
        $this->cuenta($gestorUser, 'email-2', 'activo', 2);

        Http::preventStrayRequests();
        Http::fake([
            'https://email-1.test/*' => Http::sequence()
                ->push(['active' => false])
                ->push(['active' => false]),
            'https://email-2.test/*' => Http::sequence()
                ->push(['error' => 'fallo'], 500)
                ->push(['active' => false])
                ->push(['active' => false]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend-manager', $gestorUser), [
            'motivo_suspension_gestor' => 'Ausencia temporal',
        ])->assertRedirect(route('gestor-users.show', $gestorUser));

        $this->assertSame(GestorUserStatus::Activo, $gestorUser->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Deshabilitado, $cuentaOk->fresh()->estado);
        $this->assertSame(2, $gestorUser->cuentasSuspendidasPorGestor()->count());

        $filaFallida = ProvisioningOperationAccount::query()
            ->where('estado', OperationAccountStatus::Error)
            ->sole();
        $this->assertSame(OperationStatus::Fallida, $filaFallida->operacion->estado);

        app(ProvisioningOperationService::class)->reintentar($filaFallida);

        $operacionReintentada = $filaFallida->operacion->fresh();
        $this->assertSame(OperationStatus::Completada, $operacionReintentada->estado, $operacionReintentada->cuentas()->pluck('mensaje')->implode(' | '));
        $this->assertSame(GestorUserStatus::Suspendido, $gestorUser->fresh()->estado);
        $this->assertSame(OperationStatus::Completada, $filaFallida->operacion->fresh()->estado);
        $this->assertSame(2, $gestorUser->cuentasSuspendidasPorGestor()->count());
    }

    public function test_gestor_sin_cuentas_activas_puede_suspenderse_y_reactivarse_sin_tocar_otras_cuentas(): void
    {
        $gestorUser = $this->gestor();
        $cuentaSuspendida = $this->cuenta($gestorUser, 'email-1', 'suspendido', 1);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend-manager', $gestorUser), [
            'motivo_suspension_gestor' => 'Ausencia temporal',
        ])->assertRedirect(route('gestor-users.show', $gestorUser));

        $this->assertSame(GestorUserStatus::Suspendido, $gestorUser->fresh()->estado);

        $this->post(route('gestor-users.reactivate-manager', $gestorUser))
            ->assertRedirect(route('gestor-users.show', $gestorUser));

        $this->assertSame(GestorUserStatus::Activo, $gestorUser->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Suspendido, $cuentaSuspendida->fresh()->estado);
    }

    public function test_solo_admin_puede_suspender_gestor_y_el_motivo_es_obligatorio(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, 'email-1', 'activo', 1);

        $this->actingAs(User::factory()->operador()->create());
        $this->post(route('gestor-users.suspend-manager', $gestorUser), [
            'motivo_suspension_gestor' => 'Ausencia temporal',
        ])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('gestor-users.suspend-manager', $gestorUser))
            ->assertSessionHasErrors('motivo_suspension_gestor');
    }

    public function test_fecha_de_fin_de_suspension_no_puede_ser_anterior_a_hoy(): void
    {
        $gestorUser = $this->gestor();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend-manager', $gestorUser), [
            'motivo_suspension_gestor' => 'Ausencia temporal',
            'fin_suspension_gestor' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('fin_suspension_gestor');

        $this->assertSame(GestorUserStatus::Activo, $gestorUser->fresh()->estado);
        $this->assertNull($gestorUser->fresh()->fin_suspension_gestor);
    }

    public function test_no_se_puede_crear_ni_reactivar_cuentas_individualmente_mientras_el_gestor_esta_suspendido(): void
    {
        $gestorUser = $this->gestor();
        $account = $this->cuenta($gestorUser, 'email-1', 'deshabilitado', 1);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('gestor-users.accounts.create', $gestorUser))
            ->assertOk();

        $gestorUser->update(['estado' => GestorUserStatus::Suspendido]);
        $this->get(route('gestor-users.accounts.create', $gestorUser))
            ->assertForbidden();
        $this->post(route('subsystems.accounts.action', [$account->subsystem, $account]), [
            'operation' => 'enable',
        ])->assertForbidden();
    }
}
