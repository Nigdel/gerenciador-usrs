<?php

namespace Tests\Feature\Subsystems\Glpi;

use App\Services\Subsystems\GlpiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — updateUser() y loginEnUso() de GLPI.
 *
 * En GLPI todo el payload va envuelto en 'input', y el login es el campo
 * 'name' — que no se sincroniza: cambiarlo sería cambiar la identidad de la
 * cuenta, igual que en los demás drivers.
 */
class UpdateUserTest extends SubsystemTestCase
{
    private function glpi(): GlpiService
    {
        return app(GlpiService::class);
    }

    public function test_actualiza_el_nombre_real_y_el_email(): void
    {
        $account = $this->makeAccount('glpi');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response(['id' => 42], 200)]);

        $result = $this->glpi()->updateUser($account, [
            'nombre_completo' => 'Ana Paula Silva',
            'email_personal' => 'ana@empresa.com.br',
        ]);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/User/glpi-42')
            && ($request['input']['realname'] ?? null) === 'Ana Paula Silva'
            && ($request['input']['_useremails'][0] ?? null) === 'ana@empresa.com.br');
    }

    public function test_el_login_no_se_toca(): void
    {
        $account = $this->makeAccount('glpi');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response([], 200)]);

        $this->glpi()->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        // 'name' es el login de GLPI. Mandarlo sería cambiar la identidad de
        // la cuenta, que es justo lo que una sincronización de contacto no
        // debe hacer.
        Http::assertSent(fn ($request) => ! array_key_exists('name', $request['input']));
    }

    public function test_sin_email_no_se_manda_una_lista_vacia(): void
    {
        $account = $this->makeAccount('glpi');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response([], 200)]);

        $this->glpi()->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        // _useremails vacío le diría a GLPI que el usuario no tiene correo.
        Http::assertSent(fn ($request) => ! array_key_exists('_useremails', $request['input']));
    }

    public function test_un_rechazo_de_glpi_es_un_fallo(): void
    {
        $account = $this->makeAccount('glpi');
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response(['error' => 'boom'], 400)]);

        $this->assertFalse($this->glpi()->updateUser($account, ['nombre_completo' => 'Ana Silva'])->success);
    }

    public function test_un_login_existente_devuelve_el_login_ocupado(): void
    {
        $subsistema = $this->makeAccount('glpi')->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response([
            'data' => [[42, 'ana.silva']],
        ])]);

        $this->assertTrue($this->glpi()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_un_login_que_solo_empieza_igual_sigue_libre(): void
    {
        $subsistema = $this->makeAccount('glpi')->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response([
            // La búsqueda de GLPI es 'contains', así que un login libre
            // devuelve filas que lo contienen. Darlas por ocupadas haría que
            // el generador se saltara al sufijo numérico sin necesidad.
            'data' => [[44, 'jperez.gomez']],
        ])]);

        $this->assertFalse($this->glpi()->loginEnUso('jperez', 'klios', $subsistema));
    }

    public function test_sin_resultados_el_login_esta_libre(): void
    {
        $subsistema = $this->makeAccount('glpi')->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response(['data' => []])]);

        $this->assertFalse($this->glpi()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_una_averia_no_declara_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('glpi')->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://glpi.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertNull($this->glpi()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_glpi_caido_no_declara_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('glpi')->subsystem;
        Http::preventStrayRequests();
        Http::fake(fn (Request $peticion) => throw new ConnectionException('Connection timed out'));

        $this->assertNull($this->glpi()->loginEnUso('ana.silva', 'klios', $subsistema));
    }
}
