<?php

namespace Tests\Feature\Subsystems\Email;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_updates_the_mailbox_password(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
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
            'external_account_id' => 'mail-42',
            'estado' => 'activo',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes/mail-42' => Http::response([])]);

        $result = app(EmailService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/mailboxes/mail-42')
            && $request['password'] === 'NewPassword123!');
    }
}
