<?php

namespace Tests\Feature\Subsystems\EntraId;

use App\Models\Subsystem;
use App\Services\Subsystems\EntraIdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_entra_id_creates_a_user_when_the_upn_is_not_found(): void
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
        Http::fake([
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response([
                'access_token' => 'entra-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/users/ana.silva%40klios.test*' => Http::response([], 404),
            'https://graph.microsoft.com/v1.0/users' => Http::response(['id' => 'entra-42']),
        ]);

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('entra-42', $result->externalAccountId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1.0/users'));
    }

    public function test_entra_id_reports_when_graph_rejects_user_creation(): void
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
        Http::fake([
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response([
                'access_token' => 'entra-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/users/ana.silva%40klios.test*' => Http::response([], 404),
            'https://graph.microsoft.com/v1.0/users' => Http::response([], 400),
        ]);

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('Entra ID rechazó la creación del usuario', $result->mensaje);
    }
}
