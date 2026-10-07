<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_slack_reports_that_password_reset_is_not_supported(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Slack',
            'slug' => 'slack',
            'api_url' => 'https://slack.test',
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
            'credencial_usuario' => 'ana.silva',
            'external_account_id' => 'slack-42',
            'estado' => 'activo',
        ]);
        Http::preventStrayRequests();

        $result = app(SlackService::class)->resetPassword($account, 'NewPassword123!');

        $this->assertFalse($result->success);
        $this->assertSame('Slack no permite cambiar la contraseña de usuarios vía API', $result->mensaje);
        Http::assertNothingSent();
    }
}
