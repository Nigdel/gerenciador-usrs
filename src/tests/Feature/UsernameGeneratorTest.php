<?php

namespace Tests\Feature;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\UsernameGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 2.8 — Generador de usuario robusto.
 *
 * Comprueba la disponibilidad del login en todos los subsistemas, no solo en
 * el proveedor de identidad, y distingue tres situaciones que antes se
 * confundían: "libre", "ocupado" y "no se pudo comprobar".
 */
class UsernameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function subsistema(string $slug, array $apiConfig = [], ?string $apiUrl = null): Subsystem
    {
        return Subsystem::create([
            'nombre' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => $apiUrl ?? 'https://'.$slug.'.test/api',
            'api_config' => $apiConfig,
            'es_proveedor_identidad' => $slug === 'adagio',
            'activo' => true,
        ]);
    }

    /**
     * Config mínima de Adagio. Sin email/password no hay token, y sin token la
     * consulta de disponibilidad falla antes de salir a la red: eso ya es un
     * "no comprobable" y falsearía el resto del test.
     */
    private function adagioConfig(): array
    {
        return [
            'dominio' => 'klios.com.br',
            'email' => 'integracion@klios.com.br',
            'password' => 'secreto',
            'entidad_default' => 'klios',
        ];
    }

    /**
     * Un homónimo local. La columna 'usuario' es UNIQUE, así que esto es una
     * colisión real: el alta de otro Juan Pérez no puede reutilizar ese login.
     * Cada uno necesita CPF propio, también único.
     */
    private function homonimo(string $usuario, int $indice): GestorUser
    {
        return GestorUser::create([
            'nombre_completo' => 'Persona Numero '.$indice,
            'cpf' => sprintf('%08d-00', $indice),
            'password_general' => 'Password123!',
            'usuario' => $usuario,
            'empresa' => 'klios',
        ]);
    }

    /**
     * Base sobre la que cada test declara solo lo que necesita: Adagio se
     * autentica y responde, y todo lo demás devuelve 404 (= libre).
     *
     * El subsistema de Adagio necesita email/password en api_config, porque sin
     * ellos no hay token y la consulta de disponibilidad no llega ni a salir.
     * Un token cacheado entre tests es justo lo que hace que esto no valga:
     * Cache::remember() sobrevive a RefreshDatabase.
     */
    private function fakeAdagioResponde(): void
    {
        Cache::flush();

        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response([], 404));
    }

    private function generar(): UsernameGeneratorService
    {
        return app(UsernameGeneratorService::class);
    }

    public function test_propone_nombre_apellido_cuando_no_hay_colision(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();

        $this->assertSame('juan.perez', $this->generar()->proponer('Juan Perez', 'klios'));
    }

    public function test_cae_al_sufijo_cuando_el_nombre_esta_ocupado(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();
        $this->homonimo('juan.perez', 1);

        $this->assertSame('juan.perez2', $this->generar()->proponer('Juan Perez', 'klios'));
    }

    public function test_cae_al_segundo_apellido_antes_del_sufijo(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();
        $this->homonimo('juan.perez', 1);

        $this->assertSame('juan.gomez', $this->generar()->proponer('Juan Carlos Perez Gomez', 'klios'));
    }

    public function test_el_sufijo_cuelga_del_segundo_apellido_si_este_esta_ocupado(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();
        $this->homonimo('juan.perez', 1);
        $this->homonimo('juan.gomez', 2);

        $this->assertSame('juan.gomez2', $this->generar()->proponer('Juan Carlos Perez Gomez', 'klios'));
    }

    public function test_consulta_todos_los_subsistemas_activos_no_solo_adagio(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->subsistema('email', ['dominio' => 'klios.com.br']);

        Cache::flush();

        // El buzón de correo está ocupado aunque Adagio diga que está libre.
        Http::preventStrayRequests();
        Http::fake(fn ($request) => match (true) {
            str_contains($request->url(), '/kliosAnalise/login') => Http::response(['token' => 'adagio-token']),
            str_contains($request->url(), 'mailboxes/juan.perez%40klios.com.br') => Http::response(['id' => 7]),
            default => Http::response([], 404),
        });

        $this->assertSame('juan.perez2', $this->generar()->proponer('Juan Perez', 'klios'));

        // Y se preguntó de verdad al servicio de correo, no se dio por libre.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'juan.perez%40klios.com.br'));
    }

    public function test_un_subsistema_caido_no_impide_proponer_pero_avisa(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->subsistema('email', ['dominio' => 'klios.com.br']);

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response(['error' => 'boom'], 500));

        $resultado = $this->generar()->proponerConAviso('Juan Perez', 'klios');

        // Se propone igualmente (bloquear el alta por una caída sería peor), pero
        // el operador tiene que saber que el login no está verificado.
        $this->assertSame('juan.perez', $resultado['usuario']);
        $this->assertContains('email', $resultado['no_verificados']);
        $this->assertStringContainsString('email', $resultado['mensaje']);
    }

    public function test_sin_subsistemas_que_no_respondan_no_hay_aviso(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();

        $resultado = $this->generar()->proponerConAviso('Juan Perez', 'klios');

        $this->assertSame([], $resultado['no_verificados']);
        $this->assertNull($resultado['mensaje']);
    }

    public function test_un_subsistema_sin_dominio_configurado_no_se_da_por_consultado(): void
    {
        // Sin api_config.dominio, Adagio no puede armar el email que usa como
        // credencial: no se puede comprobar, y fingir que sí llevaría a proponer
        // un login ocupado creyendo que está libre.
        $this->subsistema('adagio');
        $this->fakeAdagioResponde();

        $resultado = $this->generar()->proponerConAviso('Juan Perez', 'klios');

        $this->assertSame('juan.perez', $resultado['usuario']);
        $this->assertContains('adagio', $resultado['no_verificados']);
    }

    public function test_el_aviso_solo_mentiona_los_subsistemas_del_candidato_elegido(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->subsistema('email', ['dominio' => 'klios.com.br']);

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => match (true) {
            str_contains($request->url(), '/kliosAnalise/login') => Http::response(['token' => 'adagio-token']),
            str_contains($request->url(), 'email.test') => Http::response([], 404),
            default => Http::response(['error' => 'boom'], 500),
        });

        // Adagio está caído y el buzón de correo no: al probar "juan.perez" no
        // se puede decidir, pero el buzón sí dice que está libre, así que el
        // siguiente candidato (el sufijo) debe reintentarlo contra ambos.
        $resultado = $this->generar()->proponerConAviso('Juan Perez', 'klios');

        $this->assertSame('juan.perez', $resultado['usuario']);
        $this->assertSame(['adagio'], $resultado['no_verificados']);
    }

    public function test_lanza_si_no_encuentra_ningun_login_libre(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();

        // Un homónimo por cada candidato a probar: el nombre base y los 99
        // sufijos. Antes el bucle terminaba en el sufijo 100 y lo devolvía
        // **sin comprobar**, de modo que proponía un login que ya sabía
        // ocupado y el alta reventaba después con un error de duplicado.
        $this->homonimo('juan.perez', 0);
        for ($i = 2; $i <= 100; $i++) {
            $this->homonimo('juan.perez'.$i, $i);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se encontró ningún login libre');

        $this->generar()->proponer('Juan Perez', 'klios');
    }

    public function test_no_consulta_los_subsistemas_inactivos(): void
    {
        $this->subsistema('email', ['dominio' => 'klios.com.br'])->update(['activo' => false]);
        $this->subsistema('adagio', $this->adagioConfig());
        $this->fakeAdagioResponde();

        $resultado = $this->generar()->proponerConAviso('Juan Perez', 'klios');

        $this->assertSame([], $resultado['no_verificados']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'email.test'));
    }

    public function test_el_alta_avisa_cuando_el_login_no_se_pudo_verificar(): void
    {
        $this->subsistema('adagio', $this->adagioConfig());

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/kliosAnalise/login')
            ? Http::response(['token' => 'adagio-token'])
            : Http::response(['error' => 'boom'], 500));

        $this->actingAs(User::factory()->admin()->create());

        // Sin 'usuario' a propósito: el alta tiene que pasar por el generador.
        $this->post(route('gestor-users.store'), [
            'nombre_completo' => 'Juan Perez',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'empresa' => 'klios',
            'subsistemas' => ['adagio'],
        ])->assertRedirect();

        // Aunque el alta en Adagio falle, el aviso del login tiene que llegar:
        // es justo en el escenario de "algo no responde" cuando más importa.
        $this->assertNotEmpty(session('warning'));
    }
}
