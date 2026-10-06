<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\TemporaryPasswordGenerator;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'cpf' => '12345678901',
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
}
