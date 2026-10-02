<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Services\Subsystems\SlackService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class EnableUserTest extends SubsystemTestCase
{
    public function test_slack_reactivates_the_scim_user(): void
    {
        $account = $this->makeAccount('slack', ['scim_habilitado' => true, 'token' => 'slack-token']);
        Http::preventStrayRequests();
        Http::fake(['https://slack.test/scim/v1/Users/slack-42' => Http::response([])]);

        $result = app(SlackService::class)->reactivateUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && ($request['Operations'][0]['value'] ?? null) === true);
    }
}
