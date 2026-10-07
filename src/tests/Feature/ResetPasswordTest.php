<?php

namespace Tests\Feature;

use App\Enums\OperationAccountStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationType;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\TemporaryPasswordGenerator;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 2.4 — Restablecimiento de contraseña.
 *
 * Cubre el camino que estaba sin implementar: los drivers ya sabían
 * restablecer, pero el orquestador devolvía 'Not implemented yet'.
 */
class ResetPasswordTest extends TestCase
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

    private function subsistema(string $slug): Subsystem
    {
        return Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => 'https://'.$slug.'.test',
            'api_config' => [],
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

    public function test_restablece_la_contrasena_en_la_cuenta(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $resultado = app(UserProvisioningService::class)->resetAllPasswords($gestorUser);

        $this->assertCount(1, $resultado['resultados']);
        $this->assertTrue($resultado['resultados'][0]['exito']);
        $this->assertSame('email', $resultado['resultados'][0]['subsistema']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && ($request['password'] ?? null) === $resultado['contrasena']);
    }

    public function test_actualiza_tambien_la_contrasena_general_del_gestor(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $resultado = app(UserProvisioningService::class)->resetAllPasswords($gestorUser);

        $general = $gestorUser->fresh()->password_general;

        // Se guarda hasheada, nunca en claro.
        $this->assertNotSame($resultado['contrasena'], $general);
        $this->assertTrue(Hash::check($resultado['contrasena'], $general));
        $this->assertFalse(Hash::check('PasswordViejo1!', $general));
    }

    public function test_una_sola_contrasena_para_todas_las_cuentas(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response(['id' => 'email-42']),
            'https://glpi.test/*' => Http::response([]),
        ]);

        $resultado = app(UserProvisioningService::class)->resetAllPasswords($gestorUser);

        $this->assertCount(2, $resultado['resultados']);
        $this->assertTrue($resultado['resultados'][0]['exito']);
        $this->assertTrue($resultado['resultados'][1]['exito']);

        // El mismo login, la misma clave: el operador no la repite por subsistema.
        $enviadas = Http::recorded()
            ->map(fn ($pair) => $pair[0]['password'] ?? null)
            ->filter(fn ($clave) => $clave !== null)
            ->unique()
            ->all();

        $this->assertSame([$resultado['contrasena']], $enviadas);
    }

    public function test_la_contrasena_generada_cumple_el_formato_de_samba(): void
    {
        // '#' y '!' no son decorativos: Samba/AD rechaza lo que no cumple complexity.
        $this->assertMatchesRegularExpression(
            '/^Klios#[0-9a-f]{8}!$/',
            app(TemporaryPasswordGenerator::class)->generar()
        );
    }

    public function test_genera_una_contrasena_distinta_cada_vez(): void
    {
        $generador = app(TemporaryPasswordGenerator::class);

        $this->assertNotSame($generador->generar(), $generador->generar());
    }

    public function test_informa_de_los_fallos_sin_abortar_el_resto(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        $this->cuenta($gestorUser, $this->subsistema('glpi'));
        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response(['error' => 'boom'], 500),
            'https://glpi.test/*' => Http::response([]),
        ]);

        $resultado = app(UserProvisioningService::class)->resetAllPasswords($gestorUser);

        // El de email falla, el de glpi se intenta igual.
        $this->assertFalse($resultado['resultados'][0]['exito']);
        $this->assertTrue($resultado['resultados'][1]['exito']);
    }

    public function test_el_boton_muestra_la_contrasena_una_sola_vez(): void
    {
        $this->actingAs(User::factory()->operador()->create());
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $this->post(route('gestor-users.reset-password', $gestorUser))
            ->assertRedirect(route('gestor-users.show', $gestorUser))
            ->assertSessionHas('contrasena_temporal')
            ->assertSessionHas('success');

        $this->assertMatchesRegularExpression('/^Klios#[0-9a-f]{8}!$/', session('contrasena_temporal'));

        // Se ve al seguir la redirección. Cada reset genera una contraseña
        // distinta, así que se comprueba el formato y no el valor anterior.
        $siguiente = $this->followingRedirects()
            ->post(route('gestor-users.reset-password', $gestorUser))
            ->assertOk()
            ->assertSee('Contraseña temporal');

        $this->assertMatchesRegularExpression('/Klios#[0-9a-f]{8}!/', $siguiente->getContent());

        // ...y al recargar ya no está, porque el flash es de una sola pasada.
        $recarga = $this->get(route('gestor-users.show', $gestorUser))->assertOk();

        $this->assertDoesNotMatchRegularExpression('/Klios#[0-9a-f]{8}!/', $recarga->getContent());
    }

    public function test_un_auditor_no_puede_restablecer_contrasenas(): void
    {
        $this->actingAs(User::factory()->auditor()->create());
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));
        Http::preventStrayRequests();

        $this->post(route('gestor-users.reset-password', $gestorUser))->assertForbidden();
    }

    public function test_un_subsistema_que_no_se_puede_resolver_no_rompe_el_resto(): void
    {
        $gestorUser = $this->gestor();
        $roto = $this->subsistema('inexistente');
        $roto->update(['activo' => false]);
        $this->cuenta($gestorUser, $roto);
        $this->cuenta($gestorUser, $this->subsistema('glpi'));

        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response([])]);

        $resultado = app(UserProvisioningService::class)->resetAllPasswords($gestorUser->fresh());

        // Se indexa por subsistema, no por posición: el orden de las cuentas
        // no es parte del contrato.
        $porSubsistema = collect($resultado['resultados'])->keyBy('subsistema');

        $this->assertCount(2, $porSubsistema);
        $this->assertTrue($porSubsistema['glpi']['exito']);
        $this->assertNotEmpty($porSubsistema['inexistente']['mensaje']);
    }

    /**
     * Sprint 2.2 — El restablecimiento queda registrado.
     *
     * Hasta aquí no dejaba rastro: no había fila en provisioning_operations ni
     * aparecía en el listado, y un cambio de contraseña es de las cosas que
     * más hace falta poder auditar. Se registra como operación ya cerrada,
     * sin encolar nada, porque la contraseña temporal hay que entregársela al
     * operador y un worker no tiene a quién.
     */
    public function test_el_restablecimiento_queda_registrado_como_operacion(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response([])]);

        app(UserProvisioningService::class)->resetAllPasswords($gestorUser->fresh());

        $operacion = ProvisioningOperation::query()->sole();

        $this->assertSame(OperationType::ResetPassword, $operacion->tipo);
        $this->assertSame(OperationStatus::Completada, $operacion->estado);
        $this->assertSame(1, $operacion->exitos);
        $this->assertSame(0, $operacion->errores);
        $this->assertNotNull($operacion->terminada_at);
        $this->assertSame('email', $operacion->cuentas()->sole()->subsistema);
    }

    public function test_la_operacion_de_reset_no_guarda_la_contrasena(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response([])]);

        $resultado = app(UserProvisioningService::class)->resetAllPasswords($gestorUser->fresh());

        $operacion = ProvisioningOperation::query()->sole();

        // El payload va cifrado, pero no tiene por qué guardar el secreto: la
        // contraseña ya está en el flash del operador y no hay ninguna razón
        // para dejar una copia más en la base.
        $this->assertArrayNotHasKey('password_general', $operacion->payload ?? []);
        $this->assertStringNotContainsString(
            $resultado['contrasena'],
            json_encode($operacion->payload ?? [], JSON_THROW_ON_ERROR),
        );
    }

    public function test_no_se_encola_nada_para_el_reset(): void
    {
        $gestorUser = $this->gestor();
        $this->cuenta($gestorUser, $this->subsistema('email'));

        Queue::fake();

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response([])]);

        app(UserProvisioningService::class)->resetAllPasswords($gestorUser->fresh());

        // Encolar aquí significaría que el passwordGeneral se queda en el
        // payload hasta que el worker pase, con la entrega al operador sin
        // resolver por el camino.
        Queue::assertNothingPushed();
    }

    public function test_el_reset_fallido_registra_cada_cuenta_con_su_mensaje(): void
    {
        $gestorUser = $this->gestor();
        $ok = $this->cuenta($gestorUser, $this->subsistema('email'));
        $roto = $this->cuenta($gestorUser, $this->subsistema('glpi'));

        Http::preventStrayRequests();
        Http::fake([
            'https://email.test/*' => Http::response([]),
            'https://glpi.test/*' => Http::response(['message' => 'Credenciales rechazadas'], 403),
        ]);

        app(UserProvisioningService::class)->resetAllPasswords($gestorUser->fresh());

        $operacion = ProvisioningOperation::query()->sole();
        $filas = $operacion->cuentas()->get()->keyBy('user_subsystem_account_id');

        // Una cuenta que salió bien y otra que no: la operación tiene que
        // reflejar las dos, no solo decir que hubo un fallo.
        $this->assertSame(OperationAccountStatus::Ok, $filas[$ok->id]->estado);
        $this->assertSame(OperationAccountStatus::Error, $filas[$roto->id]->estado);
        $this->assertSame(OperationStatus::Fallida, $operacion->refresh()->estado);
        $this->assertSame(1, $operacion->errores);
        $this->assertSame(1, $operacion->exitos);
    }

    public function test_el_reset_no_bloquea_el_usuario_para_otras_operaciones(): void
    {
        $gestorUser = $this->gestor();
        $cuenta = $this->cuenta($gestorUser, $this->subsistema('email'));

        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response([])]);

        app(UserProvisioningService::class)->resetAllPasswords($gestorUser->fresh());

        // La operación del reset nace cerrada, así que no puede estar
        // bloqueando al usuario (Sprint 1.4). Dos resets seguidos tienen que
        // poder hacerse, que es justo lo que hace un operador que no lee bien
        // la contraseña que le sale en pantalla.
        $servicio = app(UserProvisioningService::class);
        $servicio->resetAllPasswords($gestorUser->fresh());

        $this->assertSame(2, ProvisioningOperation::query()->count());
        $this->assertSame(
            [OperationStatus::Completada, OperationStatus::Completada],
            ProvisioningOperation::query()->orderBy('id')->get()->map(fn ($o) => $o->estado)->all(),
        );
    }
}
