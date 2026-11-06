<?php

namespace Tests\Unit;

use App\Models\Subsystem;
use App\Services\Import\EntraIdRemoteAccountEnumerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraIdRemoteAccountEnumeratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_usa_el_upn_como_email_cuando_el_campo_mail_esta_vacio(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'EntraId',
            'slug' => 'entraid',
            'api_url' => 'https://graph.microsoft.com',
            'api_config' => ['accounts' => ['klios' => [
                'tenant_id' => 'tenant-1',
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
                'dominio' => 'klios.test',
            ]]],
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'entra-token', 'expires_in' => 3600]);
            }

            if (str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/users')) {
                return Http::response(['value' => [[
                    'id' => 'entra-42',
                    'accountEnabled' => true,
                    'userPrincipalName' => 'ana.silva@klios.test',
                    'mail' => null,
                    'employeeId' => null,
                    'displayName' => 'Ana Silva',
                ]]]);
            }

            return Http::response([], 404);
        });

        $cuentas = (new EntraIdRemoteAccountEnumerator)->enumerar($subsystem);

        $this->assertCount(1, $cuentas);
        $this->assertSame('ana.silva@klios.test', $cuentas[0]->email);
        $this->assertSame('ana.silva', $cuentas[0]->login);
    }

    public function test_usa_el_campo_mail_cuando_esta_presente(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'EntraId',
            'slug' => 'entraid',
            'api_url' => 'https://graph.microsoft.com',
            'api_config' => ['accounts' => ['klios' => [
                'tenant_id' => 'tenant-1',
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
                'dominio' => 'klios.test',
            ]]],
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'entra-token', 'expires_in' => 3600]);
            }

            if (str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/users')) {
                return Http::response(['value' => [[
                    'id' => 'entra-43',
                    'accountEnabled' => true,
                    'userPrincipalName' => 'ana.silva@klios.test',
                    'mail' => 'ana.silva@klios.test',
                    'employeeId' => null,
                    'displayName' => 'Ana Silva',
                ]]]);
            }

            return Http::response([], 404);
        });

        $cuentas = (new EntraIdRemoteAccountEnumerator)->enumerar($subsystem);

        $this->assertCount(1, $cuentas);
        $this->assertSame('ana.silva@klios.test', $cuentas[0]->email);
    }
}
