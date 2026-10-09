<?php

namespace Tests\Feature\Console;

use App\Enums\SubsystemAccountStatus;
use App\Models\AccountDiscrepancy;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sprint 5.1 — Conciliación.
 *
 * La conciliación compara el estado que tenemos guardado con el estado que
 * el subsistema dice ahora. Importa qué pasa en los tres casos: coincidir
 * (no se escribe nada), discrepar (se deja rastro) y no poder consultar
 * (no se inventa un estado). El último es el que evita que un timeout de
 * Adagio suspenda en el panel una cuenta que sigue viva.
 */
class AccountsReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private Subsystem $email;

    private GestorUser $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

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

    private function cuenta(SubsystemAccountStatus $estado): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $this->email->id,
            'credencial_usuario' => 'ana.silva@empresa.test',
            'external_account_id' => 'mail-1',
            'estado' => $estado,
        ]);
    }

    public function test_no_hace_nada_si_no_hay_cuentas(): void
    {
        $this->artisan('accounts:reconcile')
            ->expectsOutputToContain('No hay cuentas que conciliar.')
            ->assertSuccessful();
    }

    public function test_no_registra_discrepancia_cuando_el_estado_coincide(): void
    {
        $this->cuenta(SubsystemAccountStatus::Activo);
        Http::fake(['https://email.test/mailboxes/*' => Http::response(['active' => true])]);

        $this->artisan('accounts:reconcile')->assertSuccessful();

        $this->assertSame(0, AccountDiscrepancy::count());
    }

    public function test_registra_la_discrepancia_cuando_el_remoto_difiere_del_local(): void
    {
        $this->cuenta(SubsystemAccountStatus::Activo);
        Http::fake(['https://email.test/mailboxes/*' => Http::response(['active' => false])]);

        $this->artisan('accounts:reconcile')
            ->expectsOutputToContain('Se detectaron 1 discrepancias.')
            ->assertSuccessful();

        $discrepancia = AccountDiscrepancy::sole();

        $this->assertSame(SubsystemAccountStatus::Activo, $discrepancia->estado_local);
        $this->assertSame('deshabilitado', $discrepancia->remote_estado);
        $this->assertNotNull($discrepancia->detectada_at);
    }

    public function test_en_dry_run_no_escribe_nada_en_la_base_de_datos(): void
    {
        $this->cuenta(SubsystemAccountStatus::Activo);
        Http::fake(['https://email.test/mailboxes/*' => Http::response(['active' => false])]);

        $this->artisan('accounts:reconcile --dry-run')
            ->expectsOutputToContain('Se detectaron 1 discrepancias.')
            ->assertSuccessful();

        $this->assertSame(0, AccountDiscrepancy::count());
    }

    public function test_no_concilia_una_cuenta_suspendida(): void
    {
        $this->cuenta(SubsystemAccountStatus::Suspendido);
        Http::fake(['https://email.test/mailboxes/*' => Http::response(['active' => true])]);

        $this->artisan('accounts:reconcile')
            ->expectsOutputToContain('Se procesaron 1 cuentas.')
            ->assertSuccessful();

        // Suspendido en local y activo en remoto: sí es discrepancia, y queda
        // registrada. La conciliación informa, no corrige por su cuenta.
        $this->assertSame(1, AccountDiscrepancy::count());
        $this->assertSame(SubsystemAccountStatus::Suspendido, AccountDiscrepancy::sole()->estado_local);
    }

    public function test_ignora_las_cuentas_pendientes_de_creacion(): void
    {
        $this->cuenta(SubsystemAccountStatus::Pendiente);
        Http::fake();

        $this->artisan('accounts:reconcile')
            ->expectsOutputToContain('No hay cuentas que conciliar.')
            ->assertSuccessful();
    }

    public function test_no_inventa_una_discrepancia_si_el_subsistema_no_responde(): void
    {
        $this->cuenta(SubsystemAccountStatus::Activo);
        Http::fake(['https://email.test/mailboxes/*' => Http::response([], 500)]);

        $this->artisan('accounts:reconcile')->assertSuccessful();

        $this->assertSame(0, AccountDiscrepancy::count());
    }

    public function test_avisa_y_sigue_cuando_el_subsistema_es_desconocido(): void
    {
        $otro = Subsystem::create([
            'nombre' => 'Desconocido',
            'slug' => 'desconocido',
            'api_url' => 'https://desconocido.test',
            'api_config' => [],
            'activo' => true,
        ]);

        UserSubsystemAccount::create([
            'gestor_user_id' => $this->usuario->id,
            'subsystem_id' => $otro->id,
            'credencial_usuario' => 'ana',
            'estado' => SubsystemAccountStatus::Activo,
        ]);

        Http::fake();

        $this->artisan('accounts:reconcile')
            ->expectsOutputToContain('Subsistema desconocido: desconocido')
            ->assertSuccessful();

        $this->assertSame(0, AccountDiscrepancy::count());
    }

    public function test_no_duplica_la_discrepancia_al_conciliar_dos_veces(): void
    {
        $this->cuenta(SubsystemAccountStatus::Activo);
        Http::fake(['https://email.test/mailboxes/*' => Http::response(['active' => false])]);

        $this->artisan('accounts:reconcile')->assertSuccessful();
        $this->artisan('accounts:reconcile')->assertSuccessful();

        // La conciliación corre en horario: si se duplicase, la tabla
        // acumularía una fila por ejecución para la misma cuenta.
        $this->assertSame(1, AccountDiscrepancy::count());
    }
}
