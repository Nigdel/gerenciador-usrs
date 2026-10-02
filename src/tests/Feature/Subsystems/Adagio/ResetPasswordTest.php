<?php

namespace Tests\Feature\Subsystems\Adagio;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\AdagioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_adagio_sends_a_password_reset_email(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Adagio',
            'slug' => 'adagio',
            'api_url' => 'https://adagio.test/api',
            'api_config' => ['timeout' => 5],
        ]);
        $user = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678901',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'Acme',
        ]);
        $account = UserSubsystemAccount::create([
            'gestor_user_id' => $user->id,
            'subsystem_id' => $subsystem->id,
            'credencial_usuario' => 'ana@example.test',
            'external_account_id' => 'adagio-42',
            'estado' => 'activo',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://adagio.test/password/email' => Http::response(['message' => 'sent'])]);

        $result = app(AdagioService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://adagio.test/password/email'
            && $request['email'] === 'ana@example.test');
    }
}
