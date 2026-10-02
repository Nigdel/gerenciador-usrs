<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DisableUserTest extends SubsystemTestCase
{
    public function test_chatwoot_disables_the_agent(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response([])]);

        $result = app(ChatwootService::class)->disableUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents/chatwoot-42'));
    }
}
