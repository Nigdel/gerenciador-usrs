<?php

namespace Tests\Feature\Subsystems\Email;

use App\Services\Subsystems\EmailService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class GetUserStatusTest extends SubsystemTestCase
{
    public function test_email_returns_the_mailbox_status(): void
    {
        $account = $this->makeAccount('email');
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes/email-42' => Http::response(['active' => true])]);

        $result = app(EmailService::class)->getUserStatus($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/mailboxes/email-42'));
    }
}
