<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class EnableUserTest extends SubsystemTestCase
{
    public function test_chatwoot_reactivates_the_agent_by_restoring_its_access(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => [
                'account_id' => 77,
                'role' => 'agent',
                'team_ids' => [4],
                'inbox_ids' => [16],
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);

        $result = app(ChatwootService::class)->reactivateUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);

        // El agente sigue existiendo: la reactivación le devuelve la inbox y
        // los equipos que tenía guardados en configuracion_adicional.
        $this->assertSame('42', $result->externalAccountId);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['inbox_id'] === 16
            && $request['user_ids'] === [42]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/4/team_members')
            && $request['user_ids'] === [42]);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));
    }

    public function test_chatwoot_creates_the_agent_again_when_it_no_longer_exists(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => [
                'account_id' => 77,
                'role' => 'agent',
                'team_ids' => [4],
                'inbox_ids' => [16],
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['error' => 'not found'], 404),
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 99]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
        ]);

        $result = app(ChatwootService::class)->reactivateUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);

        // El id del agente nuevo se devuelve para que quien reactiva lo
        // persista: el anterior ya no vale.
        $this->assertSame('99', $result->externalAccountId);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));

        // El agente nuevo recibe los mismos accesos que tenía el anterior.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['inbox_id'] === 16
            && $request['user_ids'] === [99]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/4/team_members')
            && $request['user_ids'] === [99]);
    }

    public function test_chatwoot_reactivation_reuses_the_data_of_the_managed_user(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => ['inbox_ids' => [16], 'team_ids' => []],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['error' => 'not found'], 404),
            'https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 99]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
        ]);

        app(ChatwootService::class)->reactivateUser($account);

        Http::assertSent(function ($request) use ($account) {
            $body = $request->data();

            // Sin email_personal, createUser() aplica su propio fallback
            // usuario@empresa; lo que importa aquí es que el alta reutiliza los
            // datos del usuario gestionado en vez de inventar un agente nuevo.
            return $request->method() === 'POST'
                && ($body['name'] ?? null) === $account->user->nombre_completo
                && ($body['email'] ?? null) === $account->user->usuario.'@'.$account->user->empresa
                && ($body['role'] ?? null) === 'agent';
        });
    }

    public function test_chatwoot_reports_a_failure_when_the_access_cannot_be_restored(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => ['inbox_ids' => [16], 'team_ids' => []],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/agents/42' => Http::response(['id' => 42]),
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['error' => 'boom'], 500),
        ]);

        $result = app(ChatwootService::class)->reactivateUser($account);

        $this->assertFalse($result->success);
    }
}
