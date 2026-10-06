<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Services\Subsystems\SlackService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DisableUserTest extends SubsystemTestCase
{
    public function test_slack_disables_the_scim_user(): void
    {
        $account = $this->makeAccount('slack', ['scim_habilitado' => true, 'token' => 'slack-token']);
        Http::preventStrayRequests();
        Http::fake(['https://slack.test/scim/v1/Users/slack-42' => Http::response([])]);

        $result = app(SlackService::class)->disableUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
        // Deshabilitar, no borrar: la baja (Fase 2.6) tiene que ser reversible,
        // así que el usuario SCIM se marca inactivo en lugar de hacer DELETE.
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/scim/v1/Users/slack-42')
            && $request['Operations'][0]['path'] === 'active'
            && $request['Operations'][0]['value'] === false);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }
}
