<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class SuspendUserTest extends SubsystemTestCase
{
    public function test_chatwoot_suspends_an_agent_by_disabling_it(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response([])]);

        $result = app(ChatwootService::class)->suspendUser($account, []);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);

        // La suspensión va por disableUser(): en Chatwoot se quita al agente del
        // account, porque no hay estado deshabilitado. La consecuencia es que
        // reactivar una suspensión de Chatwoot implica recrear el agente.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/agents/chatwoot-42'));
    }
}
