<?php

namespace Tests\Feature\Subsystems\Chatwoot;

use App\Services\Subsystems\ChatwootService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class EnableUserTest extends SubsystemTestCase
{
    public function test_chatwoot_reactivates_the_agent_by_creating_it_again(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 99])]);

        $result = app(ChatwootService::class)->reactivateUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);

        // Chatwoot no tiene estado "deshabilitado": la baja elimina al agente, así
        // que reactivar solo puede ser un alta nueva. El id del agente nuevo se
        // devuelve para que quien reactiva lo persista.
        $this->assertSame('99', $result->externalAccountId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/accounts/77/agents'));
    }

    public function test_chatwoot_reactivation_reuses_the_data_of_the_managed_user(): void
    {
        $account = $this->makeAccount('chatwoot', ['accounts' => ['klios' => 77]]);
        Http::preventStrayRequests();
        Http::fake(['https://chatwoot.test/api/v1/accounts/77/agents' => Http::response(['id' => 99])]);

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
}
