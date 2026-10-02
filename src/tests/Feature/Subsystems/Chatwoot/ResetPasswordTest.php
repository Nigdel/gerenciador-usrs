<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\ChatwootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatwoot_updates_the_platform_user_password(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['platform_token' => 'platform-token'],
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => 'chat-42',
            'estado' => 'activo',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/platform/api/v1/users/chat-42' => Http::response([])]);

        $result = app(ChatwootService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/platform/api/v1/users/chat-42')
            && $request['password'] === 'NewPassword123!');
    }
}
