<?php

namespace Tests\Feature\Subsystems\Email;

use App\Services\Subsystems\EmailService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class DisableUserTest extends SubsystemTestCase
{
    public function test_email_disables_the_mailbox(): void
    {
        $account = $this->makeAccount('email');
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes/email-42' => Http::response([])]);

        $result = app(EmailService::class)->disableUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('deshabilitado', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/mailboxes/email-42'));
    }
}
