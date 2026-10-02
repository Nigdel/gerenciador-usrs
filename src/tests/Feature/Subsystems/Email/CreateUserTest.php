<?php

namespace Tests\Feature\Subsystems\Email;

use App\Models\Subsystem;
use App\Services\Subsystems\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_creates_a_mailbox_on_the_configured_domain(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
            'api_config' => ['dominio' => 'example.test'],
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes' => Http::response(['id' => 42])]);

        $result = app(EmailService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'Acme',
            'password_general' => 'Password123!',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('ana.silva@example.test', $result->credencialUsuario);
        $this->assertSame('42', $result->externalAccountId);
    }

    public function test_email_reports_when_mailbox_creation_fails(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Email',
            'slug' => 'email',
            'api_url' => 'https://email.test',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes' => Http::response([], 500)]);

        $result = app(EmailService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'Acme',
        ], $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('No se pudo crear la casilla de correo', $result->mensaje);
    }
}
