<?php

namespace Tests\Feature\Subsystems\Email;

use App\Services\Subsystems\EmailService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Subsystems\SubsystemTestCase;

class EnableUserTest extends SubsystemTestCase
{
    public function test_email_reactivates_the_mailbox(): void
    {
        $account = $this->makeAccount('email');
        Http::preventStrayRequests();
        Http::fake(['https://email.test/mailboxes/email-42' => Http::response([])]);

        $result = app(EmailService::class)->reactivateUser($account);

        $this->assertTrue($result->success);
        $this->assertSame('activo', $result->estado);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && ($request['active'] ?? null) === true);
    }
}
