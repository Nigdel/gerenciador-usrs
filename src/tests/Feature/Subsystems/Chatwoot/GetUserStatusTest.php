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

    public function test_chatwoot_treats_a_missing_agent_as_disabled(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response(['error' => 'not found'], 404)]);

        $result = app(ChatwootService::class)->getUserStatus($account);

        // Un 404 no es un fallo de comunicación sino el estado real del agente:
        // en Chatwoot no existe deshabilitado, y "no está" es lo más cercano a
        // "no tiene acceso". Sin esto la baja (Fase 2.6) no llegaría a confirmarse
        // nunca en este subsistema.
        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
    }

    public function test_chatwoot_still_reports_a_real_error_as_a_failure(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents/chatwoot-42' => Http::response(['error' => 'boom'], 500)]);

        $result = app(ChatwootService::class)->getUserStatus($account);

        // Solo el 404/410 significa "no existe"; cualquier otro error es una
        // avería y no debe interpretarse como "el usuario está deshabilitado".
        $this->assertFalse($result->success);
    }
}
