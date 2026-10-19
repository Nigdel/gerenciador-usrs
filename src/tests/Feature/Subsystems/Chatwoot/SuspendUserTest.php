<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class SuspendUserTest extends SubsystemTestCase
{
    public function test_chatwoot_suspends_the_agent_by_removing_its_access(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => [
                'account_id' => 77,
                'role' => 'agent',
                'team_ids' => [4, 8],
                'inbox_ids' => [16],
            ],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/4/team_members' => Http::response([]),
            'https://chatwoot.test/api/v1/accounts/77/teams/8/team_members' => Http::response([]),
        ]);

        $result = app(ChatwootService::class)->suspendUser($account, []);

        $this->assertTrue($result->success);
        $this->assertSame('suspendido', $result->estado);

        // Chatwoot no tiene estado "suspendido": la suspensión se le quita el
        // acceso, desasociándolo de su inbox y de sus equipos.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v1/accounts/77/inbox_members')
            && $request['inbox_id'] === 16
            && $request['user_ids'] === [42]);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/4/team_members')
            && $request['user_ids'] === [42]);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v1/accounts/77/teams/8/team_members')
            && $request['user_ids'] === [42]);
    }

    public function test_chatwoot_does_not_delete_the_agent_when_suspending_it(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => ['inbox_ids' => [16], 'team_ids' => []],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response([]),
        ]);

        $result = app(ChatwootService::class)->suspendUser($account, []);

        $this->assertTrue($result->success);

        // El agente sigue existiendo: si se borrara, la reactivación no
        // podría devolverle los accesos y la suspensión no sería reversible.
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/agents/42'));
    }

    public function test_chatwoot_reports_a_failure_when_the_access_cannot_be_removed(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77], 'inboxes' => ['klios' => 16]]);
        $account->update([
            'external_account_id' => '42',
            'configuracion_adicional' => ['inbox_ids' => [16], 'team_ids' => []],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://chatwoot.test/api/v1/accounts/77/inbox_members' => Http::response(['error' => 'boom'], 500),
        ]);

        $result = app(ChatwootService::class)->suspendUser($account, []);

        $this->assertFalse($result->success);
    }
}
