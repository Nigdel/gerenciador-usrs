<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class GetUserStatusTest extends SubsystemTestCase
{
    public function test_chatwoot_returns_the_agent_availability(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response(['availability' => 'online'])]);

        $result = app(ChatwootService::class)->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/agents/chatwoot-42'));
    }
}
