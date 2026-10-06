<?php

namespace Tests\Feature;

use App\Enums\ApiAbility;
use App\Models\AccountStateLog;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\AccountReactivationService;
use App\Services\UserSuspensionService;
use App\Support\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Histórico de cambios por cuenta de usuario gestionado.
 *
 * El objetivo es que el rastro que la Fase 2.5 borra al reactivar (motivo y
 * fechas de suspensión) quede a salvo, y que cualquier cambio de estado quede
 * registrado con su autor y su origen.
 */
class AccountStateLogTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ActorContext::olvidar();

        parent::tearDown();
    }

    private function cuentaActiva(string $slug = 'email'): UserSubsystemAccount
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

    public function test_el_alta_de_una_cuenta_queda_registrada(): void
    {
        $this->cuentaActiva();

        $entrada = AccountStateLog::sole();

        $this->assertSame(AccountStateLog::CREATED, $entrada->evento);
        $this->assertSame('scheduler', $entrada->origen);
        $this->assertNull($entrada->actor_id);
        $this->assertSame('activo', $entrada->cambios['estado']['hasta']);
        $this->assertStringContainsString('Crea la cuenta', $entrada->descripcion);
    }

    public function test_suspender_registra_el_cambio_de_estado_y_el_motivo(): void
    {
        $email = $this->cuentaActiva();
        // actingAs fija el actor pero no hay petición: el origen lo decide la
        // ruta, así que hay que hacer la suspensión desde una de verdad.
        $operador = User::factory()->operador()->create(['name' => 'Carlos Pérez']);
        $this->fakeEmail(false);
        $this->actingAs($operador);

        $this->post(route('gestor-users.suspend', $email->user), [
            'subsistemas' => ['email'],
            'motivo_suspension' => 'Licencia',
            'inicio_suspension' => now()->toDateString(),
        ])->assertRedirect();

        app(UserSuspensionService::class)->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Licencia',
            'inicio_suspension' => now()->toDateString(),
        ]);

        $entrada = AccountStateLog::where('evento', AccountStateLog::UPDATED)->sole();

        $this->assertSame('suspendido', $entrada->cambios['estado']['hasta']);
        $this->assertSame('Licencia', $entrada->cambios['motivo_suspension']['hasta']);
        $this->assertSame('web', $entrada->origen);
        $this->assertSame('Carlos Pérez', $entrada->actor_nombre);
    }

    public function test_reactivar_deja_a_salvo_el_rastro_que_la_fase_2_5_borra(): void
    {
        // El motivo de la suspensión se limpia en la cuenta al reactivar; el
        // histórico es lo único que lo conserva.
        $email = $this->cuentaActiva();
        $email->update([
            'estado' => 'suspendido',
            'inicio_suspension' => now()->subDays(10),
            'fin_suspension' => now()->subDay(),
            'motivo_suspension' => 'Licencia',
        ]);

        $this->fakeEmail(true);
        app(AccountReactivationService::class)->reactivarVencidas();

        // Hay varias entradas updated: el update inicial que simula la suspensión y
        // luego la reactivación. Se mira la última, que es la que nos interesa.
        $entrada = AccountStateLog::where('evento', AccountStateLog::UPDATED)->latest('id')->first();

        $this->assertSame('activo', $entrada->cambios['estado']['hasta']);
        $this->assertNotNull($entrada->cambios['inicio_suspension']['desde']);
        $this->assertNull($entrada->cambios['inicio_suspension']['hasta']);
        $this->assertSame('Licencia', $entrada->cambios['motivo_suspension']['desde']);
        $this->assertNull($entrada->cambios['motivo_suspension']['hasta']);

        // Y en la cuenta ya no queda.
        $email->refresh();
        $this->assertNull($email->motivo_suspension);
    }

    public function test_un_update_que_no_cambia_nada_no_registra_nada(): void
    {
        $email = $this->cuentaActiva();
        $entradaInicial = AccountStateLog::count();

        $email->update(['estado' => 'activo']);   // mismo valor
        $email->touch();

        $this->assertSame($entradaInicial, AccountStateLog::count());
    }

    public function test_la_suspension_programada_y_su_aplicacion_quedan_registradas(): void
    {
        $email = $this->cuentaActiva();

        app(UserSuspensionService::class)->suspender([
            'usuario' => $email->user->usuario,
            'motivo_suspension' => 'Fin de contrato',
            'inicio_suspension' => now()->addMonth()->toDateString(),
        ]);

        $programada = AccountStateLog::where('evento', AccountStateLog::UPDATED)->sole();
        $this->assertSame('pendiente', $programada->cambios['estado']['hasta']);

        $this->fakeEmail(false);
        Carbon::setTestNow(now()->addMonths(2));

        try {
            $this->artisan('accounts:apply-pending-suspensions')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $aplicada = AccountStateLog::where('evento', AccountStateLog::UPDATED)
            ->latest('id')->first();

        $this->assertSame('suspendido', $aplicada->cambios['estado']['hasta']);
        $this->assertSame('pendiente', $aplicada->cambios['estado']['desde']);
    }

    public function test_el_borrado_registra_y_no_arrastra_el_historico(): void
    {
        $email = $this->cuentaActiva();
        $this->actingAs(User::factory()->admin()->create());

        $email->delete();

        $entrada = AccountStateLog::where('evento', AccountStateLog::DELETED)->sole();
        $this->assertStringContainsString('Elimina la cuenta', $entrada->descripcion);

        // SET NULL, no CASCADE: el alta sigue existiendo.
        $alta = AccountStateLog::where('evento', AccountStateLog::CREATED)->sole();
        $this->assertNull($alta->fresh()->user_subsystem_account_id);
    }

    public function test_el_nombre_del_actor_queda_congelado_tras_borrar_al_usuario(): void
    {
        $operador = User::factory()->operador()->create(['name' => 'Carlos Pérez']);
        $this->actingAs($operador);

        $email = $this->cuentaActiva();
        $email->update(['estado' => 'deshabilitado']);

        $operador->delete();

        $entrada = AccountStateLog::where('evento', AccountStateLog::UPDATED)->sole();
        $this->assertNull($entrada->actor_id);
        $this->assertSame('Carlos Pérez', $entrada->actor_nombre);
        $this->assertSame('Carlos Pérez', $entrada->autor());
    }

    public function test_el_scheduler_registra_sin_actor_y_con_origen_propio(): void
    {
        $email = $this->cuentaActiva();
        $email->update(['estado' => 'deshabilitado']);

        $entrada = AccountStateLog::where('evento', AccountStateLog::UPDATED)->sole();

        $this->assertSame('scheduler', $entrada->origen);
        $this->assertNull($entrada->actor_id);
        $this->assertSame('Sistema', $entrada->autor());
    }

    public function test_la_api_registra_con_origen_api(): void
    {
        $this->tokenConAbility(ApiAbility::Provisionar);
        Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test',
            'api_config' => ['email' => 'a@b.com', 'password' => 'secret'],
            'activo' => true,
            'es_proveedor_identidad' => true,
        ]);
        Http::fake([
            'adagio.test/kliosAnalise/login' => Http::response(['token' => 'fake-token'], 200),
            'adagio.test/*' => Http::response(null, 404),
        ]);

        $this->postJson('/api/usuarios/provisionar', [
            'cpf' => '12345678901',
            'nombre_completo' => 'Api Test User',
            'empresa' => 'Test Company',
        ])->assertStatus(201);

        $entrada = AccountStateLog::where('evento', AccountStateLog::CREATED)->first();

        $this->assertNotNull($entrada);
        $this->assertSame('api', $entrada->origen);
    }

    public function test_la_ficha_del_usuario_muestra_el_historico(): void
    {
        $operador = User::factory()->admin()->create(['name' => 'Carlos Pérez']);
        $this->actingAs($operador);

        $email = $this->cuentaActiva();
        $email->update(['estado' => 'suspendido', 'motivo_suspension' => 'Licencia']);

        $this->get(route('gestor-users.show', $email->user))
            ->assertOk()
            ->assertSee('Histórico')
            ->assertSee('Suspende la cuenta')
            ->assertSee('Carlos Pérez');
    }

    private function fakeEmail(bool $activo): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/mailboxes/email-42' => Http::response(['id' => 'email-42', 'active' => $activo]),
        ]);
    }

    /** Igual que el helper de UserApiTest, que es privado a esa clase. */
    private function tokenConAbility(ApiAbility $ability): self
    {
        $plain = User::factory()->operador()->create()
            ->createToken('test', [$ability->value])
            ->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain);
    }
}
