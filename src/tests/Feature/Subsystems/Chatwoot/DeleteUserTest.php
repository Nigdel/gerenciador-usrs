<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DeleteUserTest extends SubsystemTestCase
{
    public function test_chatwoot_deletes_the_agent_from_the_account(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response([], 204)]);

        $service = app(ChatwootService::class);
        $result = $service->deleteUser($account);

        $this->assertTrue($service->supportsDeleteUser());
        $this->assertTrue($result->success);
        $this->assertSame('eliminado', $result->estado);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents/chatwoot-42'));
    }

    public function test_chatwoot_does_not_report_success_when_the_delete_fails(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response(['error' => 'boom'], 500)]);

        $result = app(ChatwootService::class)->deleteUser($account);

        $this->assertFalse($result->success);
    }
}
