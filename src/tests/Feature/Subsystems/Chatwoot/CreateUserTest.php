<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Models\Subsystem;
use App\Services\Subsystems\ChatwootService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response(['payload' => ['success' => true]]),
            'https://chatwoot.test/api/v1/accounts/77/teams/8/team_members' => Http::response(['payload' => ['success' => true]]),
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
            'teams_asignados' => true,
        ], $result->configuracionAdicional);

        // El alta de agentes no admite team_ids: la pertenencia se crea con
        // un POST por equipo.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents')
            && ! isset($request['team_ids']));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/4/team_members')
            && $request['user_ids'] === [42]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/8/team_members')
            && $request['user_ids'] === [42]);
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

    public function test_chatwoot_reports_when_a_team_assignment_fails_but_keeps_the_agent(): void
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
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response(['error' => 'forbidden'], 403),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['payload' => ['success' => true]]),
        ]);

        $result = app(ChatwootService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'empresa' => 'klios',
            'subsystem_config' => ['chatwoot' => ['teams' => [4]]],
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->externalAccountId);
        $this->assertSame('Agente creado, pero no se pudo añadir a uno o más equipos.', $result->mensaje);
        $this->assertFalse($result->configuracionAdicional['teams_asignados']);
        $this->assertSame([4], $result->configuracionAdicional['team_ids']);
    }

    public function test_chatwoot_accepts_the_teams_already_unwrapped_from_the_subsystem_config(): void
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
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response(['payload' => ['success' => true]]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['payload' => ['success' => true]]),
        ]);

        $result = app(ChatwootService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
            'subsystem_config' => ['teams' => [4]],
        ], $subsystem);

        $this->assertSame([4], $result->configuracionAdicional['team_ids']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/teams/4/team_members'));
    }

    public function test_chatwoot_reutiliza_el_agente_existente_por_email_en_vez_de_fallar(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            // GET: el agente ya está en el account. POST: Chatwoot lo
            // rechazaría con 422 por email duplicado, pero el servicio no debe
            // llegar a intentarlo.
            'https://chatwoot.test/api/v1/accounts/77/agents' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response([
                        ['id' => 42, 'email' => 'ana@example.test', 'name' => 'Ana Silva'],
                    ]);
                }

                return Http::response(['error' => 'Email has already been taken'], 422);
            },
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response(['payload' => ['success' => true]]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['payload' => ['success' => true]]),
        ]);

        $result = app(ChatwootService::class)->createUser([
            'nombre_completo' => 'Ana Silva',
            'usuario' => 'ana.silva',
            'email_personal' => 'ana@example.test',
            'empresa' => 'klios',
            'subsystem_config' => ['chatwoot' => ['teams' => [4]]],
        ], $subsystem);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->externalAccountId);
        $this->assertSame('El agente ya existía en Chatwoot, se reutilizó.', $result->mensaje);
        $this->assertSame([16], $result->configuracionAdicional['inbox_ids']);
        $this->assertSame([4], $result->configuracionAdicional['team_ids']);
        $this->assertTrue($result->configuracionAdicional['inbox_asignado']);
        $this->assertTrue($result->configuracionAdicional['teams_asignados']);

        // No se intentó crear un agente que ya existía.
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));
    }

    public function test_chatwoot_reutiliza_el_agente_si_el_alta_falla_por_duplicado_entre_la_comprobacion_y_el_post(): void
    {
        $subsystem = Subsystem::create([
            'nombre' => 'Chatwoot',
            'slug' => 'chatwoot',
            'api_url' => 'https://chatwoot.test',
            'api_config' => ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            // 1) comprobación previa: no está; 2) POST: otro proceso lo creó y
            // Chatwoot responde duplicado; 3) búsqueda posterior: ya está.
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::sequence()
                ->push([], 200)
                ->push(['error' => 'Email has already been taken'], 422)
                ->push([['id' => 42, 'email' => 'ana@example.test', 'name' => 'Ana Silva']], 200),
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
        $this->assertSame('El agente ya existía en Chatwoot, se reutilizó.', $result->mensaje);
        $this->assertTrue($result->configuracionAdicional['inbox_asignado']);
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
