<?php

namespace Tests\Feature;

use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Models\AccountStateLog;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use App\Services\UserOffboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 2.6 — Baja completa (offboarding).
 *
 * La baja deshabilita las cuentas, no las borra: es reversible y deja rastro.
 *
 * Desde la Fase 3.2 la baja sale de la petición: se crea una operación con una
 * fila por cuenta y la ejecuta un job cada una. Estos tests invocan los jobs a
 * mano con la cadena real de servicios, así que lo que se comprueba sigue siendo
 * el efecto por cuenta y no cuándo se ejecuta.
 */
class OffboardingTest extends TestCase
{
    use RefreshDatabase;

    private function gestor(string $nombre = 'ana.silva'): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'PasswordViejo1!',
            'usuario' => $nombre,
            'empresa' => 'klios',
        ]);
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

    private function cuenta(GestorUser $gestorUser, Subsystem $subsistema, string $estado = 'activo'): UserSubsystemAccount
    {
        return UserSubsystemAccount::create([
            'gestor_user_id' => $gestorUser->id,
            'subsystem_id' => $subsistema->id,
            'credencial_usuario' => $gestorUser->usuario,
            'external_account_id' => $subsistema->slug.'-42',
            'estado' => $estado,
        ]);
    }

    /**
     * @return array{resultados: array<int, array>}
     */
    private function darDeBaja(GestorUser $gestorUser, string $motivo): array
    {
        $servicio = app(UserOffboardingService::class);

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Baja,
            $gestorUser,
            $servicio->cuentasABajas($gestorUser),
            ['motivo_baja' => $motivo],
        );

        return ['resultados' => $this->procesarOperacion($operacion)];
    }

    /**
     * @return array{resultados: array<int, array>}
     */
    private function reactivar(GestorUser $gestorUser): array
    {
        $servicio = app(UserOffboardingService::class);

        $operacion = app(ProvisioningOperationService::class)->describir(
            OperationType::Reactivacion,
            $gestorUser,
            $servicio->cuentasAReactivar($gestorUser),
        );

        return ['resultados' => $this->procesarOperacion($operacion)];
    }

    public function test_la_baja_deshabilita_las_cuentas_y_marca_el_usuario(): void
    {
        $gestorUser = $this->gestor();
        $cuenta = $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42', 'active' => false])]);

        $resultado = $this->darDeBaja($gestorUser, 'Renuncia');

        $this->assertTrue($resultado['resultados'][0]['exito']);
        $this->assertSame(SubsystemAccountStatus::Borrado, $cuenta->fresh()->estado);

        $gestorUser = $gestorUser->fresh();
        $this->assertTrue($gestorUser->estaDadoDeBaja());
        $this->assertSame('Renuncia', $gestorUser->motivo_baja);
        $this->assertNotNull($gestorUser->baja_at);
    }

    public function test_la_baja_no_borra_la_cuenta_del_subsistema(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42', 'active' => false])]);

        $this->darDeBaja($gestorUser, 'Renuncia');

        // Nunca se llama a deleteUser(): la baja es reversible y la cuenta
        // tiene que seguir existiendo en el subsistema.
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_si_un_subsistema_falla_el_usuario_no_queda_dado_de_baja(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response(['id' => 'email-42', 'active' => false]),
            'https://glpi.test/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $resultado = $this->darDeBaja($gestorUser, 'Renuncia');

        $porSubsistema = collect($resultado['resultados'])->keyBy('subsistema');
        $this->assertTrue($porSubsistema['email']['exito']);
        $this->assertFalse($porSubsistema['glpi']['exito']);

        // Marcarlo de baja con una cuenta viva sería mentir en el listado.
        $this->assertFalse($gestorUser->fresh()->estaDadoDeBaja());
    }

    public function test_no_se_marca_de_baja_si_el_subsistema_no_confirma_el_estado(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        // El PATCH responde bien, pero la consulta de estado sigue diciendo activa.
        Http::fake([
            'https://email.test/*' => Http::response(['id' => 'email-42', 'active' => true]),
        ]);

        $resultado = $this->darDeBaja($gestorUser, 'Renuncia');

        $this->assertFalse($resultado['resultados'][0]['exito']);
        $this->assertFalse($gestorUser->fresh()->estaDadoDeBaja());
    }

    public function test_la_reactivacion_devuelve_el_usuario_a_activo(): void
    {
        $gestorUser = $this->gestor();
        $cuenta = $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::sequence()
                ->push(['id' => 'email-42', 'active' => false])  // baja: deshabilita
                ->push(['id' => 'email-42', 'active' => false])  // baja: confirma
                ->push(['id' => 'email-42', 'active' => true])   // reactivación: activa
                ->push(['id' => 'email-42', 'active' => true]),  // reactivación: confirma
        ]);

        $this->darDeBaja($gestorUser, 'Renuncia');
        $resultado = $this->reactivar($gestorUser->fresh());

        $this->assertTrue($resultado['resultados'][0]['exito']);
        $this->assertSame(SubsystemAccountStatus::Activo, $cuenta->fresh()->estado);

        $gestorUser = $gestorUser->fresh();
        $this->assertFalse($gestorUser->estaDadoDeBaja());
        $this->assertNull($gestorUser->motivo_baja);
        $this->assertNull($gestorUser->baja_at);
    }

    public function test_no_se_puede_dar_de_baja_dos_veces(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42', 'active' => false])]);

        $servicio = app(UserOffboardingService::class);
        $this->darDeBaja($gestorUser, 'Renuncia');

        // La precondición ya no la comprueba el servicio al ejecutar, sino el
        // controlador antes de encolar: es donde el operador puede verla.
        $this->expectException(\RuntimeException::class);
        $servicio->validarBaja($gestorUser->fresh());
    }

    public function test_la_baja_exige_admin(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->operador()->create());
        $this->post(route('gestor-users.offboard', $gestorUser), ['motivo_baja' => 'Renuncia'])
            ->assertForbidden();

        $this->assertFalse($gestorUser->fresh()->estaDadoDeBaja());
    }

    public function test_el_motivo_de_la_baja_es_obligatorio(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('gestor-users.offboard', $gestorUser))
            ->assertSessionHasErrors('motivo_baja');

        $this->assertFalse($gestorUser->fresh()->estaDadoDeBaja());
    }

    public function test_el_admin_da_de_baja_desde_la_web(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42', 'active' => false])]);

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('gestor-users.offboard', $gestorUser), ['motivo_baja' => 'Fin de contrato'])
            ->assertRedirect(route('gestor-users.show', $gestorUser))
            ->assertSessionHas('success');

        $this->assertTrue($gestorUser->fresh()->estaDadoDeBaja());

        // La ficha lo dice y ofrece la vuelta atrás.
        $this->get(route('gestor-users.show', $gestorUser))
            ->assertOk()
            ->assertSee('Usuario dado de baja')
            ->assertSee('Fin de contrato')
            ->assertSee('Reactivar usuario');
    }

    public function test_en_chatwoot_la_baja_le_quita_el_acceso_al_agente_y_la_reactivacion_se_lo_devuelve(): void
    {
        $gestorUser = $this->gestor();
        $cuenta = $this->cuenta($gestorUser, $this->subsistema('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]));
        $cuenta->update([
            'external_account_id' => '42',
            'configuracion_adicional' => [
                'account_id' => 77,
                'role' => 'agent',
                'team_ids' => [4],
                'inbox_ids' => [16],
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            // La baja le quita el acceso: desasocia al agente de su inbox y
            // de sus equipos.
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
            // El agente sigue existiendo, así que la reactivación solo tiene
            // que devolverle esos mismos accesos.
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['id' => 42]),
        ]);

        $this->darDeBaja($gestorUser, 'Renuncia');
        $resultado = $this->reactivar($gestorUser->fresh());

        $this->assertTrue($resultado['resultados'][0]['exito']);

        // Chatwoot no tiene estado "deshabilitado": la baja le quita el acceso
        // al agente sin borrarlo, y la reactivación se lo devuelve. Por eso el
        // id externo no cambia.
        $this->assertSame('42', $cuenta->fresh()->external_account_id);
        $this->assertSame(SubsystemAccountStatus::Activo, $cuenta->fresh()->estado);
    }

    public function test_la_baja_queda_en_el_historico(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42', 'active' => false])]);

        $this->darDeBaja($gestorUser, 'Renuncia');

        $entrada = AccountStateLog::query()->latest('id')->first();

        $this->assertSame('updated', $entrada->evento);
        $this->assertSame('borrado', $entrada->cambios['estado']['hasta']);
        $this->assertSame('activo', $entrada->cambios['estado']['desde']);
    }
}
