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
            'https://graph.microsoft.com/v1.0/directory/deletedItems/microsoft.graph.user*' => Http::response(['value' => []]),
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

    public function test_entra_id_restores_and_enables_a_user_from_the_recycle_bin(): void
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
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'entra-token', 'expires_in' => 3600]);
            }

            if (str_contains($url, '/directory/deletedItems/microsoft.graph.user')) {
                // La búsqueda exacta por UPN no encuentra al usuario eliminado
                // porque Entra ID le antepone un GUID; mailNickname sí.
                if (str_contains($url, 'mailNickname')) {
                    return Http::response([
                        'value' => [[
                            'id' => 'entra-deleted-42',
                            'userPrincipalName' => '6483822c7c034edc86ba0899c157da01ana.silva@klios.test',
                            'displayName' => 'Ana Silva',
                            'mailNickname' => 'ana.silva',
                            'deletedDateTime' => '2026-10-01T12:00:00Z',
                        ]],
                    ]);
                }

                return Http::response(['value' => []]);
            }

            if (str_contains($url, '/directory/deletedItems/entra-deleted-42/restore')) {
                return Http::response([
                    'id' => 'entra-deleted-42',
                    'userPrincipalName' => 'ana.silva@klios.test',
                    'accountEnabled' => true,
                ], 201);
            }

            if (str_contains($url, '/v1.0/users/entra-deleted-42')) {
                return Http::response([], 204);
            }

            if (str_contains($url, '/v1.0/users/ana.silva%40klios.test')) {
                return Http::response([], 404);
            }

            if (str_ends_with($url, '/v1.0/users')) {
                return Http::response([
                    'error' => [
                        'code' => 'Request_BadRequest',
                        'message' => 'A password must be specified to create a new user.',
                    ],
                ], 400);
            }

            return Http::response([], 500);
        });

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('entra-deleted-42', $result->externalAccountId);
        $this->assertSame('ana.silva@klios.test', $result->credencialUsuario);
        $this->assertSame('activo', $result->estado);
        $this->assertStringContainsString('restaurado', (string) $result->mensaje);
        $this->assertTrue($result->raw['restaurado']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/directory/deletedItems/entra-deleted-42/restore'));
        // Ya venía habilitado: no debe intentarse ningún PATCH.
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_entra_id_enables_a_restored_user_only_when_it_was_disabled(): void
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
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'entra-token', 'expires_in' => 3600]);
            }

            if (str_contains($url, '/directory/deletedItems/microsoft.graph.user')) {
                return str_contains($url, 'mailNickname')
                    ? Http::response(['value' => [[
                        'id' => 'entra-deleted-42',
                        'userPrincipalName' => '6483822c7c034edc86ba0899c157da01ana.silva@klios.test',
                        'mailNickname' => 'ana.silva',
                    ]]])
                    : Http::response(['value' => []]);
            }

            if (str_contains($url, '/directory/deletedItems/entra-deleted-42/restore')) {
                return Http::response([
                    'id' => 'entra-deleted-42',
                    'userPrincipalName' => 'ana.silva@klios.test',
                    'accountEnabled' => false,
                ], 201);
            }

            if (str_contains($url, '/v1.0/users/entra-deleted-42')) {
                return Http::response([], 204);
            }

            if (str_contains($url, '/v1.0/users/ana.silva%40klios.test')) {
                return Http::response([], 404);
            }

            if (str_ends_with($url, '/v1.0/users')) {
                return Http::response([
                    'error' => [
                        'code' => 'Request_BadRequest',
                        'message' => 'A password must be specified to create a new user.',
                    ],
                ], 400);
            }

            return Http::response([], 500);
        });

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        $this->assertStringContainsString('habilitado', (string) $result->mensaje);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/v1.0/users/entra-deleted-42')
            && $request['accountEnabled'] === true
            && $request['userPrincipalName'] === 'ana.silva@klios.test');
    }

    public function test_entra_id_reports_when_a_deleted_user_cannot_be_restored(): void
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
            'https://graph.microsoft.com/v1.0/directory/deletedItems/microsoft.graph.user*' => Http::response([
                'value' => [[
                    'id' => 'entra-deleted-42',
                    'userPrincipalName' => 'ana.silva@klios.test',
                ]],
            ]),
            'https://graph.microsoft.com/v1.0/directory/deletedItems/entra-deleted-42/restore' => Http::response([], 403),
            'https://graph.microsoft.com/v1.0/users' => Http::response([
                'error' => [
                    'code' => 'Request_BadRequest',
                    'message' => 'Another object with the same value for property userPrincipalName already exists.',
                ],
            ], 400),
        ]);

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no se pudo restaurar', (string) $result->mensaje);
        $this->assertStringContainsString('ana.silva@klios.test', (string) $result->mensaje);
    }

    public function test_entra_id_explains_a_reserved_upn_when_the_recycle_bin_cannot_be_checked(): void
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
            'https://graph.microsoft.com/v1.0/directory/deletedItems/microsoft.graph.user*' => Http::response([], 403),
            'https://graph.microsoft.com/v1.0/users' => Http::response([
                'error' => [
                    'code' => 'Request_BadRequest',
                    'message' => 'Another object with the same value for property userPrincipalName already exists.',
                ],
            ], 400),
        ]);

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('userPrincipalName ya está reservado', (string) $result->mensaje);
        $this->assertStringContainsString('papelera de reciclaje', (string) $result->mensaje);
    }

    public function test_entra_id_reports_a_missing_password_clearly(): void
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
            'https://graph.microsoft.com/v1.0/directory/deletedItems/microsoft.graph.user*' => Http::response(['value' => []]),
            'https://graph.microsoft.com/v1.0/users' => Http::response([
                'error' => [
                    'code' => 'Request_BadRequest',
                    'message' => 'A password must be specified to create a new user.',
                ],
            ], 400),
        ]);

        $result = app(EntraIdService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ], $subsystem);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('falta la contraseña general', (string) $result->mensaje);
    }
}
