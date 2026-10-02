<?php

namespace Tests\Feature\Subsystems\Slack;

use App\Services\Subsystems\SlackService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class GetUserStatusTest extends SubsystemTestCase
{
    public function test_slack_returns_the_scim_user_status(): void
    {
        $account = $this->makeAccount('slack', ['scim_habilitado' => true, 'token' => 'slack-token']);
        Http::preventStrayRequests();
        Http::fake(['https://slack.test/scim/v1/Users/slack-42' => Http::response(['active' => true])]);

        $result = app(SlackService::class)->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/scim/v1/Users/slack-42'));
    }
}
