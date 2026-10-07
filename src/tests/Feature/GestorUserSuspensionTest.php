<?php

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 2.1 — Suspensión desde la web (gestor-users/show).
 *
 * El orquestador es UserSuspensionService, el mismo que usa la API: aquí lo
 * que se comprueba es la capa propia de la web (FormRequest, policy, ruta y
 * redirección).
 */
class GestorUserSuspensionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Varias cuentas del mismo GestorUser: la suspensión se lanza contra el
     * usuario, no contra la cuenta, así que los tests con dos subsistemas
     * necesitan que compartan propietario.
     */
    private function crearCuentas(string ...$slugs): array
    {
        $gestorUser = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $cuentas = [];
        foreach ($slugs as $slug) {
            $subsystem = Subsystem::create([
                'nombre' => ucfirst($slug),
                'slug' => $slug,
                'api_url' => 'https://'.$slug.'.test',
                'api_config' => [],
                'activo' => true,
            ]);

            $cuentas[$slug] = UserSubsystemAccount::create([
                'gestor_user_id' => $gestorUser->id,
                'subsystem_id' => $subsystem->id,
                'credencial_usuario' => 'ana.silva',
                'external_account_id' => $slug.'-42',
                'estado' => 'activo',
            ]);
        }

        return $cuentas;
    }

    /**
     * El servicio confirma la suspensión con un GET posterior: hay que
     * responder al PATCH (suspender) y al GET (confirmar) con la casilla ya
     * desactivada.
     */
    private function fakeEmailSuspendido(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => false]),
        ]);
    }

    public function test_auditor_no_puede_suspender(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->actingAs(User::factory()->auditor()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'motivo_suspension' => 'Fin de contrato',
        ])->assertForbidden();

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
    }

    public function test_admin_suspende_todas_las_cuentas_cuando_no_elige_subsistemas(): void
    {
        ['email' => $email, 'glpi' => $glpi] = $this->crearCuentas('email', 'glpi');
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/*' => Http::response(['active' => false]),
            'https://glpi.test/User/*' => Http::response(['active' => false]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'motivo_suspension' => 'Fin de contrato',
        ])->assertRedirect(route('gestor-users.show', $email->user))
            // Desde la Fase 3.2 la respuesta habla de lo encolado, no de lo
            // hecho: en este punto los jobs ya han corrido en línea por
            // QUEUE_CONNECTION=sync, pero en producción no lo han hecho todavía.
            ->assertSessionHas('success', 'La suspensión está en curso (2 cuenta(s) en cola). El resultado aparecerá en esta misma ficha.');

        $this->assertSame(1, $email->user->operaciones()->count());
        $this->assertSame(OperationStatus::Completada, $email->user->operaciones()->sole()->estado);

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
        $this->assertSame('Fin de contrato', $email->fresh()->motivo_suspension);
        $this->assertNotNull($email->fresh()->inicio_suspension);
    }

    public function test_solo_se_suspenden_los_subsistemas_marcados(): void
    {
        ['email' => $email, 'glpi' => $glpi] = $this->crearCuentas('email', 'glpi');
        $this->fakeEmailSuspendido();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'subsistemas' => ['email'],
            'motivo_suspension' => 'Licencia no renovada',
        ])->assertRedirect(route('gestor-users.show', $email->user));

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
        $this->assertSame(SubsystemAccountStatus::Activo, $glpi->fresh()->estado);

        // La cuenta no marcada no debe haber generado tráfico al subsistema.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'glpi.test'));
    }

    public function test_operador_tambien_puede_suspender(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->fakeEmailSuspendido();
        $this->actingAs(User::factory()->operador()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'motivo_suspension' => 'Fin de contrato',
        ])->assertRedirect(route('gestor-users.show', $email->user));

        $this->assertSame(SubsystemAccountStatus::Suspendido, $email->fresh()->estado);
    }

    public function test_exige_motivo(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [])
            ->assertSessionHasErrors('motivo_suspension');

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
    }

    public function test_rechaza_un_fin_anterior_al_inicio(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'motivo_suspension' => 'Licencia',
            'inicio_suspension' => '2026-10-10',
            'fin_suspension' => '2026-10-01',
        ])->assertSessionHasErrors('fin_suspension');

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
    }

    public function test_rechaza_un_subsistema_que_no_corresponde_al_usuario(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'subsistemas' => ['glpi'],
            'motivo_suspension' => 'Licencia',
        ])->assertSessionHasErrors('subsistemas.0');

        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);
    }

    public function test_avisa_cuando_el_subsistema_no_confirma_la_suspension(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => true]),
        ]);
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'motivo_suspension' => 'Fin de contrato',
        ])->assertRedirect(route('gestor-users.show', $email->user))
            // El aviso ya no va en la respuesta: la petición solo encola. El
            // operador lo ve en el panel de operaciones, y el botón de
            // reintentar del 3.3 sale de esa misma tabla persistida.
            ->assertSessionHas('success');

        // Sin confirmación remota no se marca como suspendida en la BD.
        $this->assertSame(SubsystemAccountStatus::Activo, $email->fresh()->estado);

        // Y la operación queda 'fallida' con el motivo del subsistema, que es
        // donde el operador va a mirar a partir de ahora.
        $operacion = $email->user->operaciones()->sole();

        $this->assertSame(OperationStatus::Fallida, $operacion->estado);
        $this->assertSame(1, $operacion->errores);
        $this->assertStringContainsString(
            'no confirmó',
            $operacion->cuentas()->sole()->mensaje,
        );
    }

    public function test_la_ficha_muestra_las_cuentas_suspendidas(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->fakeEmailSuspendido();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.suspend', $email->user), [
            'motivo_suspension' => 'Fin de contrato',
            'inicio_suspension' => '2026-10-01',
            'fin_suspension' => '2026-12-31',
        ]);

        $this->get(route('gestor-users.show', $email->user))
            ->assertOk()
            ->assertSee('Suspensión')
            ->assertSee('Fin de contrato')
            ->assertSee('01/10/2026')
            ->assertSee('31/12/2026');
    }

    public function test_auditor_no_ve_el_formulario_de_suspension(): void
    {
        ['email' => $email] = $this->crearCuentas('email');
        $this->actingAs(User::factory()->auditor()->create());

        $this->get(route('gestor-users.show', $email->user))
            ->assertOk()
            ->assertDontSee('Suspender cuentas', false);
    }
}
