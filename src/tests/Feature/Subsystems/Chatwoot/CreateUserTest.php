<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Models\Subsystem;
use App\Services\Subsystems\ChatwootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatwoot_creates_an_agent_in_the_selected_teams(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['payload' => ['success' => true]]),
        ]);

        $result = app(ChatwootService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'empresa' => 'klios',
            'subsystem_config' => ['chatwoot' => ['teams' => [4, 8]]],
        ], $subsystem);

        $this->assertSame([
            'account_id' => 77,
            'role' => 'agent',
            'team_ids' => [4, 8],
            'inbox_ids' => [16],
            'inbox_asignado' => true,
        ], $result->configuracionAdicional);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents')
            && $request['team_ids'] === [4, 8]);
    }

    public function test_chatwoot_creates_an_agent_in_the_company_account(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['payload' => ['success' => true]]),
        ]);

        $result = app(ChatwootService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'empresa' => 'klios',
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->externalAccountId);
        $this->assertSame([16], $result->configuracionAdicional['inbox_ids']);
        $this->assertTrue($result->configuracionAdicional['inbox_asignado']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));
    }

    public function test_chatwoot_reports_when_agent_creation_is_rejected(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['error' => 'invalid'], 422)]);

        $result = app(ChatwootService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ], $subsystem);

        $this->assertFalse($result->success);
        $this->assertSame('Chatwoot rechazó la creación del agente', $result->mensaje);
    }
}
