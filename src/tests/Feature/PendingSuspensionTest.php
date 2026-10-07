<?php

namespace Tests\Feature;

use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 2.3 — Suspensión programada.
 *
 * Si la fecha de inicio es futura, la cuenta queda 'pendiente' y no se toca
 * el subsistema; el scheduler la suspende cuando llega la fecha.
 */
class PendingSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function cuentaActiva(string $slug): UserSubsystemAccount
    {
        $subsistema = Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => 'https://'.$slug.'.test',
            'api_config' => [],
            'activo' => true,
        ]);

        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.'.$slug,
            'empresa' => 'klios',
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $slug.'-42',
            'estado' => 'activo',
        ]);
    }

    private function fakeEmail(bool $activo): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => $activo]),
        ]);
    }

    public function test_una_suspension_con_inicio_futuro_queda_pendiente_sin_tocar_el_subsistema(): void
    {
        $email = $this->cuentaActiva('email');
        Http::preventStrayRequests();

        $resultados = $this->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Fin de contrato',
            'inicio_suspension' => now()->addMonth()->toDateString(),
            'fin_suspension' => now()->addMonths(2)->toDateString(),
        ]);

        $this->assertTrue($resultados[0]['exito']);
        $this->assertStringContainsString('Suspensión programada', $resultados[0]['mensaje']);

        $email->refresh();
        $this->assertSame(SubsystemAccountStatus::Pendiente, $email->estado);
        $this->assertSame('Fin de contrato', $email->motivo_suspension);

        // Lo importante: el subsistema no se ha tocado todavía.
        Http::assertNothingSent();
    }

    public function test_una_suspension_con_inicio_pasado_suspende_inmediatamente(): void
    {
        $email = $this->cuentaActiva('email');
        $this->fakeEmail(false);

        $this->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Licencia',
            'inicio_suspension' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
    }

    public function test_el_scheduler_aplica_la_suspension_al_llegar_la_fecha(): void
    {
        $email = $this->cuentaActiva('email');
        $this->fakeEmail(false);

        // Se agenda para dentro de un mes...
        $this->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Fin de contrato',
            'inicio_suspension' => now()->addMonth()->toDateString(),
        ]);
        $this->assertSame(SubsystemAccountStatus::Pendiente, $email->fresh()->estado);

        // ...y se aplica cuando la fecha ya pasó.
        Carbon::setTestNow(now()->addMonths(2));

        try {
            $this->artisan('accounts:apply-pending-suspensions')
                ->expectsOutputToContain('Suspendidas 1 de 1 cuentas.')
                ->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $email->refresh();
        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->estado);
        $this->assertSame('Fin de contrato', $email->motivo_suspension);
    }

    public function test_no_aplica_suspensiones_pendientes_que_aun_no_ha_llegado(): void
    {
        $email = $this->cuentaActiva('email');
        Http::preventStrayRequests();

        $this->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Fin de contrato',
            'inicio_suspension' => now()->addMonth()->toDateString(),
        ]);

        $this->artisan('accounts:apply-pending-suspensions')
            ->expectsOutputToContain('No hay suspensiones agendadas pendientes de aplicar.')
            ->assertSuccessful();

        $this->assertSame(SubsystemAccountStatus::Pendiente, $email->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_una_suspension_pendiente_vencida_no_se_toca_si_el_subsistema_falla(): void
    {
        $email = $this->cuentaActiva('email');
        $this->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Fin de contrato',
            'inicio_suspension' => now()->addMonth()->toDateString(),
        ]);

        Http::preventStrayRequests();
        // El subsistema sigue sin confirmar la suspensión.
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => true]),
        ]);
        Carbon::setTestNow(now()->addMonths(2));

        try {
            $this->artisan('accounts:apply-pending-suspensions')
                ->expectsOutputToContain('El subsistema no confirmó la suspensión de la cuenta.')
                ->assertFailed();
        } finally {
            Carbon::setTestNow();
        }

        // Sigue pendiente para reintentarse en la siguiente pasada.
        $this->assertSame(SubsystemAccountStatus::Pendiente, $email->fresh()->estado);
    }

    public function test_el_ciclo_completo_programa_suspende_y_reactiva(): void
    {
        $email = $this->cuentaActiva('email');

        $this->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Licencia estacional',
            'inicio_suspension' => now()->addDay()->toDateString(),
            'fin_suspension' => now()->addWeek()->toDateString(),
        ]);
        $this->assertSame(SubsystemAccountStatus::Pendiente, $email->fresh()->estado);

        // El estado remoto cambia con el tiempo. Cada operación del servicio hace
        // DOS peticiones: la acción y luego un GET de confirmación, así que la
        // secuencia necesita una respuesta por cada una.
        //
        // Con un segundo Http::fake() no serviría: los fakes se acumulan y el
        // primero sigue ganando, hay que usar una secuencia.
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::sequence()
                ->push(['active' => false])  // PATCH: suspende
                ->push(['active' => false])  // GET: confirma la suspensión
                ->push(['active' => true])   // PATCH: reactiva
                ->push(['active' => true]),  // GET: confirma la reactivación
        ]);
        Carbon::setTestNow(now()->addDays(2));

        try {
            // 1) Llega la fecha de inicio.
            $this->artisan('accounts:apply-pending-suspensions')
                ->expectsOutputToContain('Suspendidas 1 de 1 cuentas.')
                ->assertSuccessful();
            $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);

            // 2) Todavía dentro del periodo de suspensión: no toca reactivar.
            $this->artisan('accounts:reactivate-expired')
                ->expectsOutputToContain('No hay cuentas suspendidas con la suspensión vencida.')
                ->assertSuccessful();

            $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);

            // 3) Ya pasada la fecha de fin, la reactivación de 2.2 la recoge.
            Carbon::setTestNow(now()->addDays(8));

            $this->artisan('accounts:reactivate-expired')
                ->expectsOutputToContain('Reactivadas 1 de 1 cuentas.')
                ->assertSuccessful();

            $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_se_puede_crear_una_cuenta_en_estado_pendiente_desde_el_formulario(): void
    {
        // 'pendiente' es un estado interno del servicio de suspensión: el
        // formulario no debe permitir inventar una suspensión agendada.
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.accounts.store', $this->cuentaActiva('email')->user), [
            'subsystem_id' => Subsystem::first()->id,
            'credencial_usuario' => 'otra',
            'estado' => 'pendiente',
        ])->assertSessionHasErrors('estado');
    }

    public function test_esta_programado_en_el_scheduler(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains($evento->command ?? '', 'accounts:apply-pending-suspensions'));

        $this->assertCount(1, $eventos);
        $this->assertSame('*/10 * * * *', $eventos->first()->expression);
    }
}
