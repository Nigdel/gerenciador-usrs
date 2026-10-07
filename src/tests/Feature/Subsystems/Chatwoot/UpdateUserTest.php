<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — updateUser() y loginEnUso() de Chatwoot.
 *
 * Chatwoot no tiene un endpoint para cambiar el login, así que lo que debe
 * ser único aquí es el email del agente: es contra ese valor contra el que se
 * comprueba, igual que en el alta.
 */
class UpdateUserTest extends SubsystemTestCase
{
    private function chatwoot(): ChatwootService
    {
        return app(ChatwootService::class);
    }

    private function cuenta(array $extra = [])
    {
        return $this->makeAccount('chatwoot', array_merge([
            'accounts' => ['klios' => 1, 'federal' => 2],
            'dominio' => 'empresa.com.br',
        ], $extra));
    }

    public function test_actualiza_el_nombre_del_agente(): void
    {
        $account = $this->cuenta();
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response(['id' => 42], 200)]);

        $result = $this->chatwoot()->updateUser($account, ['nombre_completo' => 'Ana Paula Silva']);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/api/v1/accounts/1/agents/chatwoot-42')
            && ($request['name'] ?? null) === 'Ana Paula Silva');
    }

    public function test_el_email_solo_se_manda_si_viene(): void
    {
        $account = $this->cuenta();
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response([], 200)]);

        $this->chatwoot()->updateUser($account, [
            'nombre_completo' => 'Ana Silva',
            'email_personal' => 'ana@empresa.com.br',
        ]);

        Http::assertSent(fn ($request) => ($request['email'] ?? null) === 'ana@empresa.com.br');

        // Sin email no se manda un null: Chatwoot lo interpretaría como
        // «borra el email del agente».
        $this->chatwoot()->updateUser($account, ['nombre_completo' => 'Ana Silva']);
        Http::assertSent(fn ($request) => ! array_key_exists('email', $request->data()));
    }

    public function test_el_account_id_se_toma_de_la_empresa_del_usuario(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 1, 'federal' => 2]], company: 'federal');
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response([], 200)]);

        $this->chatwoot()->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/accounts/2/agents/'));
    }

    public function test_un_rechazo_de_chatwoot_es_un_fallo(): void
    {
        $account = $this->cuenta();
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response(['error' => 'boom'], 422)]);

        $this->assertFalse($this->chatwoot()->updateUser($account, ['nombre_completo' => 'Ana Silva'])->success);
    }

    public function test_una_cuenta_sin_empresa_configurada_no_declara_el_login_libre(): void
    {
        // El account_id sale de api_config.accounts por empresa. Sin él, ni
        // createUser() ni esta comprobación pueden hacer nada: se declara no
        // comprobable en vez de «libre».
        $subsistema = $this->makeAccount('chatwoot')->subsystem;
        Http::preventStrayRequests();

        $this->assertNull($this->chatwoot()->loginEnUso('ana.silva', 'klios', $subsistema));
        Http::assertNothingSent();
    }

    public function test_un_agente_con_ese_email_ocupa_el_login(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response([
            'payload' => [
                ['id' => 7, 'name' => 'Otro', 'email' => 'ana.silva@empresa.com.br'],
            ],
        ])]);

        $this->assertTrue($this->chatwoot()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_la_comparacion_ignora_mayusculas(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response([
            'payload' => [['id' => 7, 'email' => 'Ana.Silva@EMPRESA.com.br']],
        ])]);

        // Chatwoot guarda el email tal cual; comparar con === dejaría pasar un
        // login que en realidad ya existe.
        $this->assertTrue($this->chatwoot()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_ningun_agente_con_ese_email_deja_el_login_libre(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response([
            'payload' => [['id' => 7, 'email' => 'otro@empresa.com.br']],
        ])]);

        $this->assertFalse($this->chatwoot()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_una_averia_no_declara_el_login_libre(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertNull($this->chatwoot()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_chatwoot_caido_no_declara_el_login_libre(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        Http::preventStrayRequests();
        Http::fake(fn (Request $peticion) => throw new ConnectionException('Connection timed out'));

        $this->assertNull($this->chatwoot()->loginEnUso('ana.silva', 'klios', $subsistema));
    }
}
