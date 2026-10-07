<?php

namespace Tests\Feature\Subsystems\Email;

use App\Services\Subsystems\EmailService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — updateUser() y loginEnUso() del driver de email.
 *
 * El servicio de correo es el que decide el login en el alta, así que su
 * loginEnUso() tiene una consecuencia que no tiene en los demás: un «no se pudo
 * comprobar» hace que el generador descarte el candidato. Devolver mal un 404
 * convertiría un servicio de correo parado en altas que nunca encuentra login.
 */
class UpdateUserTest extends SubsystemTestCase
{
    public function test_actualiza_el_nombre_de_la_casilla(): void
    {
        $account = $this->makeAccount('email');
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $result = app(EmailService::class)->updateUser($account, ['nombre_completo' => 'Ana Paula Silva']);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/mailboxes/email-42')
            && ($request['name'] ?? null) === 'Ana Paula Silva');
    }

    public function test_un_rechazo_del_servicio_es_un_fallo(): void
    {
        $account = $this->makeAccount('email');
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['error' => 'boom'], 500)]);

        $result = app(EmailService::class)->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        $this->assertFalse($result->success);
    }

    public function test_una_casilla_inexistente_devuelve_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('email', ['dominio' => 'empresa.com.br'])->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(null, 404)]);

        $this->assertFalse(app(EmailService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_una_casilla_existente_devuelve_el_login_ocupado(): void
    {
        $subsistema = $this->makeAccount('email', ['dominio' => 'empresa.com.br'])->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(['id' => 'email-42'])]);

        $this->assertTrue(app(EmailService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_se_consulta_la_direccion_completa_con_su_dominio(): void
    {
        $subsistema = $this->makeAccount('email', ['dominio' => 'empresa.com.br'])->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(null, 404)]);

        app(EmailService::class)->loginEnUso('ana.silva', 'klios', $subsistema);

        // El id de la casilla es la dirección entera, no el login suelto: sin el
        // @dominio la comprobación sería sobre un buzón que no existe.
        Http::assertSent(fn ($request) => str_contains(
            rawurldecode($request->url()),
            '/mailboxes/ana.silva@empresa.com.br',
        ));
    }

    public function test_sin_dominio_se_usa_el_de_la_empresa(): void
    {
        $subsistema = $this->makeAccount('email')->subsystem;
        Http::preventStrayRequests();
        Http::fake(['https://email.test/*' => Http::response(null, 404)]);

        app(EmailService::class)->loginEnUso('ana.silva', 'klios', $subsistema);

        Http::assertSent(fn ($request) => str_contains(rawurldecode($request->url()), 'ana.silva@klios.com.br'));
    }

    public function test_una_averia_no_declara_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('email', ['dominio' => 'empresa.com.br'])->subsystem;
        Http::preventStrayRequests();
        Http::fake(fn (Request $peticion) => throw new ConnectionException('Connection timed out'));

        $this->assertNull(app(EmailService::class)->loginEnUso('ana.silva', 'klios', $subsistema));
    }
}
