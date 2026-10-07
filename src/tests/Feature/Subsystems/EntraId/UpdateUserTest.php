<?php

namespace Tests\Feature\Subsystems\EntraId;

use App\Services\Subsystems\EntraIdService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

/**
 * Sprint 3.3 — updateUser() y loginEnUso() de Entra ID.
 *
 * Entra ID no usa el api_url del subsistema: el token sale de
 * login.microsoftonline.com y las llamadas van a graph.microsoft.com. Por eso
 * los dos hosts entran en el fake.
 */
class UpdateUserTest extends SubsystemTestCase
{
    private function entra(): EntraIdService
    {
        return app(EntraIdService::class);
    }

    private function fakes(int $graph = 204): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) use ($graph) {
            if (str_contains($peticion->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            return Http::response([], $graph);
        });
    }

    private function cuenta(array $extra = [])
    {
        return $this->makeAccount('entraid', array_merge([
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secreto',
            'dominio' => 'empresa.com.br',
        ], $extra), apiUrl: 'https://graph.test');
    }

    public function test_actualiza_el_nombre_y_el_correo(): void
    {
        $account = $this->cuenta();
        $this->fakes();

        $result = $this->entra()->updateUser($account, [
            'nombre_completo' => 'Ana Paula Silva',
            'email_personal' => 'ana@empresa.com.br',
        ]);

        $this->assertTrue($result->success, $result->mensaje ?? '');
        $this->assertSame('activo', $result->estado);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/v1.0/users/entraid-42')
            && ($request['displayName'] ?? null) === 'Ana Paula Silva'
            && ($request['mail'] ?? null) === 'ana@empresa.com.br');
    }

    public function test_el_upn_y_el_mail_nickname_no_se_tocan(): void
    {
        $account = $this->cuenta();
        $this->fakes();

        $this->entra()->updateUser($account, ['nombre_completo' => 'Ana Silva']);

        // userPrincipalName y mailNickname son la clave con la que se creó la
        // cuenta; renombrarlos es otra operación, no un cambio de datos.
        Http::assertSent(fn ($request) => ! array_key_exists('userPrincipalName', $request->data())
            && ! array_key_exists('mailNickname', $request->data()));
    }

    public function test_un_rechazo_de_graph_es_un_fallo(): void
    {
        $account = $this->cuenta();
        $this->fakes(403);

        $this->assertFalse($this->entra()->updateUser($account, ['nombre_completo' => 'Ana Silva'])->success);
    }

    public function test_un_upn_existente_devuelve_el_login_ocupado(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        $this->fakes(200);

        $this->assertTrue($this->entra()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_un_404_significa_login_libre(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        $this->fakes(404);

        // Mismo criterio que usa createUser() para no duplicar: si no existe,
        // el UPN está libre.
        $this->assertFalse($this->entra()->loginEnUso('ana.silva', 'klios', $subsistema));
    }

    public function test_se_consulta_el_upn_completo(): void
    {
        $subsistema = $this->cuenta()->subsystem;
        $this->fakes(404);

        $this->entra()->loginEnUso('ana.silva', 'klios', $subsistema);

        Http::assertSent(fn ($request) => str_contains(
            rawurldecode($request->url()),
            '/v1.0/users/ana.silva@empresa.com.br',
        ));
    }

    public function test_sin_dominio_no_se_inventa_una_comprobacion(): void
    {
        $subsistema = $this->makeAccount('entraid', [
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secreto',
        ], apiUrl: 'https://graph.test')->subsystem;

        Http::preventStrayRequests();

        $this->assertNull($this->entra()->loginEnUso('ana.silva', 'klios', $subsistema));
        Http::assertNothingSent();
    }

    public function test_una_empresa_sin_configuracion_no_declara_el_login_libre(): void
    {
        $subsistema = $this->makeAccount('entraid', [
            'accounts' => ['klios' => [
                'tenant_id' => 'tenant-1',
                'client_id' => 'client-1',
                'client_secret' => 'secreto',
                'dominio' => 'klios.com.br',
            ]],
        ], apiUrl: 'https://graph.test')->subsystem;

        Http::preventStrayRequests();

        $this->assertNull($this->entra()->loginEnUso('ana.silva', 'empresa-inexistente', $subsistema));
        Http::assertNothingSent();
    }

    public function test_graph_caido_no_declara_el_login_libre(): void
    {
        $subsistema = $this->cuenta()->subsystem;

        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) {
            if (str_contains($peticion->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            throw new ConnectionException('Connection timed out');
        });

        $this->assertNull($this->entra()->loginEnUso('ana.silva', 'klios', $subsistema));
    }
}
