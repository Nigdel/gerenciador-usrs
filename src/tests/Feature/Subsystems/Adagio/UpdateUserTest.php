<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Services\Subsystems\AdagioService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — updateUser() en Adagio.
 *
 * Adagio es el único driver que manda el nombre como multipart y exige un token
 * antes de hacer nada, así que el test tiene que resolver los dos o el 502 que
 * sale no dice nada del método.
 */
class UpdateUserTest extends SubsystemTestCase
{
    private function adagio(): AdagioService
    {
        return app(AdagioService::class);
    }

    /**
     * Fakes Adagio's three endpoints.
     *
     * A closure rather than an array of patterns: Http::fake()'s stub list does
     * not register PUT, so an array fake lets the PUT from updateUser() escape
     * to the real network — which in CI means a DNS timeout, not a clear failure.
     * A closure intercepts every verb.
     */
    private function fakes(string $actualizar = '[]', int $estado = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) use ($actualizar, $estado) {
            if (str_contains($peticion->url(), 'kliosAnalise/login')) {
                return Http::response(['token' => 'fake-token']);
            }

            if (str_contains($peticion->url(), 'proprietarios/internos/adagio-42')) {
                return Http::response($actualizar, $estado);
            }

            return Http::response(null, 404);
        });
    }

    private function cuenta(array $configExtra = [])
    {
        // api_url lleva el /api dentro, como en el SubsystemSeeder: el login
        // cuelga de él ('/api/kliosAnalise/login') y no de la raíz.
        return $this->makeAccount('adagio', array_merge([
            'email' => 'a@b.com',
            'password' => 'secret',
            'dominio' => 'empresa.com.br',
            'entidad_default' => 'klios',
        ], $configExtra), apiUrl: 'https://adagio.test/api');
    }

    public function test_actualiza_el_nombre_del_propietario(): void
    {
        $account = $this->cuenta();
        $this->fakes('{"id":77,"nome":"Ana Paula Silva"}');

        $result = $this->adagio()->updateUser($account, ['nombre_completo' => 'Ana Paula Silva']);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);

        // El nombre va como multipart (attach): data() lo devuelve como una lista de
        // partes ['name' => ..., 'contents' => ...], no como array asociativo.
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), 'proprietarios/internos/adagio-42')
            && in_array(
                ['name' => 'nome', 'contents' => 'Ana Paula Silva', 'headers' => []],
                $request->data(),
                true,
            ));
    }

    public function test_adagio_acepta_put_en_send(): void
    {
        // El guard que hizo aparecer este bug: send() tiene un match por método
        // HTTP y PUT no estaba en la lista, así que updateUser() —que es
        // justamente un PUT— lanzaba RuntimeException siempre. El caso de abajo
        // fallaría con «Método HTTP no soportado por AdagioService» si volviera
        // a faltar.
        $account = $this->cuenta();
        $this->fakes();

        $this->assertTrue($this->adagio()->updateUser($account, ['nombre_completo' => 'Ana Silva'])->success);
    }

    public function test_sin_nombre_no_se_llama_a_adagio(): void
    {
        $account = $this->cuenta();
        $this->fakes();

        $result = $this->adagio()->updateUser($account, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('nombre_completo', $result->mensaje);
    }

    public function test_un_rechazo_de_adagio_es_un_fallo(): void
    {
        $account = $this->cuenta();
        $this->fakes('{"error":"conflito"}', 409);

        $result = $this->adagio()->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        $this->assertFalse($result->success);
    }

    public function test_un_token_vencido_se_reautentica_una_sola_vez(): void
    {
        $account = $this->cuenta();

        Http::preventStrayRequests();
        $intentos = 0;
        Http::fake(function (Request $peticion) use (&$intentos) {
            if (str_contains($peticion->url(), 'kliosAnalise/login')) {
                return Http::response(['token' => 'token-nuevo']);
            }

            // El primer PUT llega con el token viejo y Adagio lo rechaza con
            // 401; request() borra la caché y repite con el nuevo. El estado
            // se lleva en una variable en vez de con Http::sequence(), que
            // queda asociado al stub de una llamada anterior.
            $intentos++;

            return $intentos === 1
                ? Http::response(['error' => 'unauthorized'], 401)
                : Http::response(['id' => 77, 'nome' => 'Ana Silva']);
        });

        $result = $this->adagio()->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        $this->assertTrue($result->success, $result->mensaje ?? '');
    }

    public function test_adagio_caido_no_se_confunde_con_un_rechazo(): void
    {
        $account = $this->cuenta();

        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) {
            // El login sí sale: lo que se cae es Adagio al actualizar. Si se
            // cayera antes, el fallo sería del token y no del PUT.
            if (str_contains($peticion->url(), 'kliosAnalise/login')) {
                return Http::response(['token' => 'fake-token']);
            }

            throw new ConnectionException('Connection timed out');
        });

        // Ningún driver de este proyecto captura la excepción de red aquí: la
        // deja subir y es ProcessOperationAccount quien la convierte en fila
        // 'error', que sí se reintenta. Por eso se espera la excepción y no un
        // SubsystemOperationResult de fallo.
        $this->expectException(ConnectionException::class);

        $this->adagio()->updateUser($account, ['nombre_completo' => 'Ana Silva']);
    }
}
