<?php

namespace Tests\Feature;

use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 2.2 — Reactivación automática de suspendencias vencidas.
 *
 * Cubre el comando accounts:reactivate-expired y su programación en el
 * scheduler, que es lo que la ejecuta sin intervención manual.
 */
class ReactivateExpiredAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cuentaSuspendida(string $slug, ?string $fin): UserSubsystemAccount
    {
        $subsistema = Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => 'https://'.$slug.'.test',
            'api_config' => [],
            'activo' => true,
        ]);

        // gestor_users.usuario y gestor_users.cpf son únicos: se derivan del
        // slug para que un test pueda crear varias cuentas del mismo helper.
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva '.$slug,
            'cpf' => '123456789'.str_pad((string) crc32($slug) % 100, 2, '0', STR_PAD_LEFT),
            'password_general' => 'Password123!',
            'usuario' => 'ana.'.$slug,
            'empresa' => 'klios',
        ]);

        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => $slug.'-42',
            'estado' => 'suspendido',
            'inicio_suspension' => now()->subDays(10),
            'fin_suspension' => $fin === null ? null : Carbon::parse($fin),
            'motivo_suspension' => 'Licencia',
        ]);
    }

    private function fakeEmailActivo(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => true]),
        ]);
    }

    public function test_reactiva_una_suspension_vencida(): void
    {
        $email = $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        $this->fakeEmailActivo();

        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('Reactivadas 1 de 1 cuentas.')
            ->assertSuccessful();

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && ($request['active'] ?? null) === true);
    }

    public function test_no_reactiva_una_suspension_que_aun_no_venece(): void
    {
        $email = $this->cuentaSuspendida('email', now()->addWeek()->toDateString());

        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('No hay cuentas suspendidas con la suspensión vencida.')
            ->assertSuccessful();

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
    }

    public function test_no_toca_una_suspension_indefinida(): void
    {
        $email = $this->cuentaSuspendida('email', null);

        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('No hay cuentas suspendidas con la suspensión vencida.')
            ->assertSuccessful();

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
    }

    public function test_no_reactiva_una_cuenta_que_ya_no_esta_suspendida(): void
    {
        $email = $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        $email->update(['estado' => 'activo']);

        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('No hay cuentas suspendidas con la suspensión vencida.')
            ->assertSuccessful();

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
    }

    public function test_limpia_el_rastro_de_la_suspension_al_reactivar(): void
    {
        // Fase 2.5: una cuenta activa no puede seguir con fechas y motivo de
        // una suspensión que ya no existe.
        $email = $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        $this->fakeEmailActivo();

        $this->artisan('accounts:reactivate-expired')->assertSuccessful();

        $email->refresh();
        $this->assertNull($email->motivo_suspension);
        $this->assertNull($email->inicio_suspension);
        $this->assertNull($email->fin_suspension);
    }

    public function test_no_escribe_el_estado_si_el_subsistema_no_confirma_la_reactivacion(): void
    {
        $email = $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        Http::preventStrayRequests();
        // El PATCH va bien pero el GET de confirmación sigue diciendo deshabilitado.
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => false]),
        ]);

        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('El subsistema no confirmó la reactivación de la cuenta.')
            ->assertFailed();

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
    }

    public function test_informa_de_los_fallos_pero_reactiva_el_resto(): void
    {
        $email = $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        $roto = $this->cuentaSuspendida('sin-driver', now()->subDay()->toDateString());
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => true]),
        ]);

        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('Reactivadas 1 de 2 cuentas.')
            ->assertFailed();

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Suspendido, $roto->fresh()->estado);
    }

    public function test_es_idempotente_al_correrlo_dos_veces(): void
    {
        $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        $this->fakeEmailActivo();

        $this->artisan('accounts:reactivate-expired')->assertSuccessful();
        $this->artisan('accounts:reactivate-expired')
            ->expectsOutputToContain('No hay cuentas suspendidas con la suspensión vencida.')
            ->assertSuccessful();
    }

    public function test_dry_run_no_toca_nada(): void
    {
        $email = $this->cuentaSuspendida('email', now()->subDay()->toDateString());
        Http::preventStrayRequests();

        $this->artisan('accounts:reactivate-expired --dry-run')
            ->expectsOutputToContain('Cuentas a reactivar: 1')
            ->assertSuccessful();

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_esta_programado_en_el_scheduler(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains($evento->command ?? '', 'accounts:reactivate-expired'));

        $this->assertCount(1, $eventos, 'accounts:reactivate-expired debe estar programado.');
        $this->assertSame('*/10 * * * *', $eventos->first()->expression);
    }
}
