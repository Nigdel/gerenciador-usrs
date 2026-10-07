<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Services\Subsystems\SlackService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — updateUser() y loginEnUso() de Slack vía SCIM.
 *
 * Slack se integra por SCIM, que va apagado por configuración. Cuando lo está,
 * el driver devuelve null en vez de «libre»: sin SCIM no hay dónde comprobar,
 * y decir que un login está libre sería inventarse una respuesta.
 */
class UpdateUserTest extends SubsystemTestCase
{
    public function test_actualiza_el_nombre_mediante_una_operacion_scim(): void
    {
        $account = $this->makeAccount('slack', ['scim_habilitado' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://slack.test/*' => Http::response([], 204)]);

        $result = app(SlackService::class)->updateUser($account, ['nombre_completo' => 'Ana Paula Silva']);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/scim/v1/Users/slack-42')
            && ($request['Operations'][0]['value']['givenName'] ?? null) === 'Ana Paula Silva');
    }

    public function test_una_cuenta_sin_id_scim_no_intenta_sincronizar(): void
    {
        // El driver no falla: no hay nada que sincronizar, y reportarlo como
        // error haría creer que Slack está rejecting la operación.
        $account = $this->makeAccount('slack', ['scim_habilitado' => true]);
        $account->update(['external_account_id' => null]);
        Http::preventStrayRequests();

        $result = app(SlackService::class)->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('SCIM', (string) $result->mensaje);
        Http::assertNothingSent();
    }

    public function test_un_rechazo_de_slack_es_un_fallo(): void
    {
        $account = $this->makeAccount('slack', ['scim_habilitado' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://slack.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertFalse(app(SlackService::class)->updateUser($account, ['nombre_completo' => 'Ana Silva'])->success);
    }

    public function test_sin_scim_no_se_comprueba_la_disponibilidad(): void
    {
        $subsistema = $this->makeAccount('slack')->subsystem;
        Http::preventStrayRequests();

        $this->assertNull(app(SlackService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
        Http::assertNothingSent();
    }

    public function test_con_scim_un_usuario_existente_ocupa_el_login(): void
    {
        $subsistema = $this->makeAccount('slack', [
            'scim_habilitado' => true,
            'dominio' => 'empresa.slack.com',
        ])->subsystem;

        Http::preventStrayRequests();
        Http::fake(['https://slack.test/*' => Http::response(['Resources' => [['id' => 'scim-1']]])]);

        $this->assertTrue(app(SlackService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_con_scim_y_sin_resultados_el_login_esta_libre(): void
    {
        $subsistema = $this->makeAccount('slack', [
            'scim_habilitado' => true,
            'dominio' => 'empresa.slack.com',
        ])->subsystem;

        Http::preventStrayRequests();
        Http::fake(['https://slack.test/*' => Http::response(['Resources' => []])]);

        $this->assertFalse(app(SlackService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_una_respuesta_sin_resources_no_declara_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('slack', [
            'scim_habilitado' => true,
            'dominio' => 'empresa.slack.com',
        ])->subsystem;

        Http::preventStrayRequests();
        Http::fake(['https://slack.test/*' => Http::response(['error' => 'algo raro'])]);

        // SCIM respondería con Resources; si no viene, no se sabe qué hay.
        $this->assertNull(app(SlackService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_slack_caido_no_declara_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('slack', [
            'scim_habilitado' => true,
            'dominio' => 'empresa.slack.com',
        ])->subsystem;

        Http::preventStrayRequests();
        Http::fake(fn (Request $peticion) => throw new ConnectionException('Connection timed out'));

        $this->assertNull(app(SlackService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_la_busqueda_se_hace_por_el_user_name_completo(): void
    {
        $subsistema = $this->makeAccount('slack', [
            'scim_habilitado' => true,
            'dominio' => 'empresa.slack.com',
        ])->subsystem;

        Http::preventStrayRequests();
        Http::fake(['https://slack.test/*' => Http::response(['Resources' => []])]);

        app(SlackService::class)->loginEnUso('ana.silva', 'klios', $subsistema);

        Http::assertSent(fn ($request) => str_contains(
            rawurldecode($request->url()),
            'userName eq "ana.silva@empresa.slack.com"',
        ));
    }
}
