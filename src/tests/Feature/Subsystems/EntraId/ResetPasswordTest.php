<?php

namespace Tests\Feature\Subsystems\EntraId;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\EntraIdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_entra_id_sets_the_password_profile(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'EntraId',
            'slug' => 'entraid',
            'api_url' => 'https://graph.microsoft.com',
            'api_config' => ['accounts' => ['Acme' => [
                'tenant_id' => 'tenant-1',
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
            ]]],
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678909',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Acme',
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana@acme.test',
            'external_account_id' => 'entra-42',
            'estado' => 'activo',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response([
                'access_token' => 'entra-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/users/entra-42' => Http::response([], 204),
        ]);

        $result = app(EntraIdService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/v1.0/users/entra-42')
            && ($request['passwordProfile']['password'] ?? null) === 'NewPassword123!'
            && ($request['passwordProfile']['forceChangePasswordNextSignIn'] ?? null) === true);
    }
}
