<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Models\Subsystem;
use App\Services\Subsystems\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_slack_records_a_manual_invitation_when_scim_is_disabled(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Slack',
            'slug' => 'slack',
            'api_url' => 'https://slack.test',
            'api_config' => ['scim_habilitado' => false],
        ]);
        Http::preventStrayRequests();

        $result = app(SlackService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('ana@example.test', $result->credencialUsuario);
        $this->assertStringContainsString('Invitación registrada manualmente', $result->mensaje);
        Http::assertNothingSent();
    }
}
