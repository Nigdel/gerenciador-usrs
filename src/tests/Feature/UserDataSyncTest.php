<?php

namespace Tests\Feature;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationType;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use App\Services\UserDataSyncService;
use App\Services\UserOffboardingService;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 2.7 — Modificación propagada.
 *
 * Sincroniza solo atributos de contacto. El login, la empresa y el CPF son la
 * clave con la que se creó la cuenta en cada subsistema y no se tocan.
 *
 * Desde la Fase 3.2 la sincronización sale de la petición. Estos tests
 * ejecutan los jobs a mano (procesarOperacion(), en Tests\TestCase) en vez de
 * apoyarse en QUEUE_CONNECTION=sync, porque lo que interesa comprobar aquí es
 * el efecto por cuenta, no cuándo se ejecuta.
 */
class UserDataSyncTest extends TestCase
{
    use RefreshDatabase;

    private function gestor(array $atributos = []): GestorUser
    {
        return GestorUser::create(array_merge([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'PasswordViejo1!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'email_personal' => 'ana@example.test',
        ], $atributos));
    }

    private function subsistema(string $slug, array $apiConfig = []): Subsystem
    {
        return Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => 'https://'.$slug.'.test',
            'api_config' => $apiConfig,
            'activo' => true,
        ]);
    }

    private function cuenta(GestorUser $gestorUser, Subsystem $subsistema): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => $gestorUser->usuario,
            'external_account_id' => $subsistema->slug.'-42',
            'estado' => 'activo',
        ]);
    }

    /**
     * Monta una operación de sincronización para las cuentas dadas y ejecuta
     * sus jobs, que es el camino que sigue el controlador desde el 3.2.
     *
     * Devuelve la misma forma que devolvía sincronizar() —{'resultados': …}—
     * para que las aserciones de estos tests no cambien solo por el refactor.
     *
     * @return array{resultados: array<int, array>}
     */
    private function sincronizar(GestorUser $gestorUser, ?array $subsistemas = null): array
    {
        $servicio = app(UserDataSyncService::class);

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Sincronizacion,
            $gestorUser,
            $servicio->cuentasASincronizar($gestorUser, $subsistemas),
        );

        return ['resultados' => $this->procesarOperacion($operacion)];
    }


    public function test_propaga_el_nombre_a_las_cuentas_de_cada_subsistema(): void
    {
        $gestorUser = $this->gestor(['nombre_completo' => 'Ana María Silva']);
        $this->cuenta($gestorUser, $this->subsistema('email'));
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response(['id' => 'email-42']),
            'https://glpi.test/*' => Http::response(['id' => 42]),
        ]);

        $resultado = $this->sincronizar($gestorUser);

        $this->assertCount(2, $resultado['resultados']);
        $this->assertTrue(collect($resultado['resultados'])->every(fn ($r) => $r['exito']));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/mailboxes/email-42')
            && $request['name'] === 'Ana María Silva');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/User/glpi-42')
            && $request['input']['realname'] === 'Ana María Silva');
    }

    public function test_no_propaga_el_login_la_empresa_ni_el_cpf(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response(['id' => 42])]);

        $this->sincronizar($gestorUser);

        // El login, la empresa y el CPF son la clave con la que se creó la
        // cuenta (name, dominio del UPN, employeeId). Propagarlos sería
        // renombrar la identidad de la cuenta, que es otra operación.
        Http::assertSent(function ($request) {
            $input = $request['input'] ?? [];

            return $request->method() === 'PUT'
                && ! array_key_exists('name', $input)
                && ! array_key_exists('comment', $input);
        });
    }

    public function test_un_subsistema_que_falla_no_rompe_el_resto(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response(['id' => 'email-42']),
            'https://glpi.test/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $resultado = $this->sincronizar($gestorUser);

        $porSubsistema = collect($resultado['resultados'])->keyBy('subsistema');
        $this->assertTrue($porSubsistema['email']['exito']);
        $this->assertFalse($porSubsistema['glpi']['exito']);
    }

    public function test_un_subsistema_que_no_lo_admite_no_se_reporta_como_fallo(): void
    {
        $gestorUser = $this->gestor();
        // Ningún driver registrado para este slug: no se puede sincronizar, pero
        // tampoco es un fallo que el operador pueda resolver reintentando.
        $this->cuenta($gestorUser, $this->subsistema('desconocido'));
        Http::preventStrayRequests();

        $resultado = $this->sincronizar($gestorUser);

        $this->assertFalse($resultado['resultados'][0]['exito']);
        $this->assertStringContainsString('driver registrado', $resultado['resultados'][0]['mensaje']);
    }

    public function test_se_puede_sincronizar_solo_algunos_subsistemas(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['id' => 42])]);

        $resultado = $this->sincronizar($gestorUser, ['email']);

        $this->assertCount(1, $resultado['resultados']);
        $this->assertSame('email', $resultado['resultados'][0]['subsistema']);
    }

    public function test_el_admin_sincroniza_desde_la_web(): void
    {
        $gestorUser = $this->gestor(['nombre_completo' => 'Ana María Silva']);
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.sync-subsystems', $gestorUser))
            ->assertRedirect(route('gestor-users.show', $gestorUser))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request['name'] === 'Ana María Silva');
    }

    public function test_auditar_no_puede_sincronizar(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->auditor()->create());
        $this->post(route('gestor-users.sync-subsystems', $gestorUser))
            ->assertForbidden();
    }

    public function test_un_usuario_sin_cuentas_no_rompe_la_sincronizacion(): void
    {
        $gestorUser = $this->gestor();
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.sync-subsystems', $gestorUser))
            ->assertRedirect(route('gestor-users.show', $gestorUser))
            ->assertSessionHas('warning');
    }

    public function test_la_sincronizacion_no_toca_la_contrasena_general(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $this->sincronizar($gestorUser);

        Http::assertNotSent(fn ($request) => $request['password'] ?? null);
    }
}
