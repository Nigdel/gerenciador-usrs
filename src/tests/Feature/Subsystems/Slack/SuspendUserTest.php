<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Services\Subsystems\SlackService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class SuspendUserTest extends SubsystemTestCase
{
    public function test_slack_suspends_the_scim_user_by_disabling_it(): void
    {
        $account = $this->makeAccount('slack', ['scim_habilitado' => true, 'token' => 'slack-token']);
        Http::preventStrayRequests();
        Http::fake(['https://slack.test/scim/v1/Users/slack-42' => Http::response([])]);

        $result = app(SlackService::class)->suspendUser($account, []);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
        // La suspensión va por disableUser(), que ya no borra el usuario SCIM
        // sino que lo marca inactivo (misma vía que la baja del 2.6).
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/scim/v1/Users/slack-42')
            && $request['Operations'][0]['path'] === 'active'
            && $request['Operations'][0]['value'] === false);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }
}
